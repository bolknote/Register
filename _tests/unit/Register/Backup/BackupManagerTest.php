<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\AdminYard\Translator;
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupContributorInterface;
use Register\Backup\BackupEntry;
use Register\Backup\BackupEncryptor;
use Register\Backup\BackupManager;
use Register\Backup\DatabaseSnapshotter;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Media\MediaMutationTracker;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BackupManagerTest extends Unit
{
    private ?string $temporaryDirectory = null;

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== null) {
            $this->deleteDirectory($this->temporaryDirectory);
        }
    }

    public function testCreatesRestorableDatabaseAndMediaZipWithoutOptionalCompressionExtensions(): void
    {
        [$manager, $directory, $encryptor] = $this->manager(retention: 2);
        $createdAt = 1_700_000_000;

        $backup = $manager->createNow($createdAt);

        self::assertFileExists($backup->path);
        self::assertSame($createdAt, $backup->createdAt);
        self::assertMatchesRegularExpression(
            '/^register-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.zip\.enc$/D',
            $backup->name,
        );

        $encryptedArchive = file_get_contents($backup->path);
        self::assertIsString($encryptedArchive);
        self::assertStringStartsWith("REGISTER-BACKUP\0\x01", $encryptedArchive);
        self::assertStringNotContainsString('image bytes', $encryptedArchive);

        $decryptedPath = $directory . '/decrypted.zip';
        $encryptor->decryptFile($backup->path, $decryptedPath);
        $rawArchive = file_get_contents($decryptedPath);
        self::assertIsString($rawArchive);
        self::assertStringStartsWith("PK\x03\x04", $rawArchive);
        self::assertStringContainsString('manifest.json', $rawArchive);
        self::assertStringContainsString('media/nested/photo.webp', $rawArchive);
        self::assertStringContainsString('image bytes', $rawArchive);

        $zipClassName = 'ZipArchive';
        if (class_exists($zipClassName)) {
            $zip      = new $zipClassName();
            $zipClass = new \ReflectionObject($zip);
            self::assertTrue($zipClass->getMethod('open')->invoke($zip, $decryptedPath));
            self::assertSame(
                'image bytes',
                $zipClass->getMethod('getFromName')->invoke($zip, 'media/nested/photo.webp'),
            );
            $manifest = $zipClass->getMethod('getFromName')->invoke($zip, 'manifest.json');
            self::assertIsString($manifest);
            self::assertStringContainsString('"format": "register-backup"', $manifest);
            self::assertStringContainsString('"driver": "sqlite"', $manifest);
            self::assertStringContainsString('"included": true', $manifest);

            $database = $zipClass->getMethod('getFromName')->invoke($zip, 'database.sqlite');
            self::assertIsString($database);
            $restoredPath = $directory . '/restored.sqlite';
            self::assertSame(\strlen($database), file_put_contents($restoredPath, $database));
            self::assertTrue($zipClass->getMethod('close')->invoke($zip));

            $restored = new \PDO('sqlite:' . $restoredPath);
            $statement = $restored->query('SELECT title FROM notes');
            if (!$statement instanceof \PDOStatement) {
                throw new \RuntimeException('Unable to query the restored backup database.');
            }

            self::assertSame('Saved note', $statement->fetchColumn());
        }
    }

    public function testUpdateSnapshotExcludesMediaWithoutReplacingLatestFullBackup(): void
    {
        [$manager, $directory, $encryptor] = $this->manager(retention: 2);
        $fullBackup = $manager->createNow(1_700_000_000);
        $snapshot = $manager->createForUpdate(now: 1_700_000_001);

        self::assertMatchesRegularExpression(
            '/^register-update-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.zip\.enc$/D',
            $snapshot->name,
        );
        self::assertSame($directory . '/backups/updates/' . $snapshot->name, $snapshot->path);
        self::assertSame($fullBackup->path, $manager->latest()?->path);

        $decryptedPath = $directory . '/update-snapshot.zip';
        $encryptor->decryptFile($snapshot->path, $decryptedPath);
        $rawArchive = file_get_contents($decryptedPath);
        self::assertIsString($rawArchive);
        self::assertStringContainsString('database.sqlite', $rawArchive);
        self::assertStringContainsString('"included": false', $rawArchive);
        self::assertStringContainsString('keep the existing media directory unchanged', $rawArchive);
        self::assertStringNotContainsString('media/nested/photo.webp', $rawArchive);
        self::assertStringNotContainsString('image bytes', $rawArchive);

        $snapshots = glob($directory . '/backups/updates/register-update-backup-*.zip.enc');
        self::assertIsArray($snapshots);
        self::assertCount(1, $snapshots);
    }

    /** @dataProvider concurrentMediaMutations */
    public function testMediaMutationAfterDatabaseSnapshotNeverPublishesAnInconsistentBackup(string $mutation): void
    {
        $state = new class {
            public bool $mutate = false;

            public string $storedFile = '';
        };
        [$manager, $directory, , $pdo] = $this->manager(
            retention: 1,
            afterSnapshot: function (\PDO $source, string $root) use ($state, $mutation): void {
                if (!$state->mutate) {
                    return;
                }

                $storage = $this->mediaStorage($root);
                if ($mutation === 'redate') {
                    $storage->redateCanonical($state->storedFile, 1_700_086_400);
                } else {
                    $storage->delete($state->storedFile);
                    if ($mutation === 'replace') {
                        $replacement = $this->uploadImage($root, 'replacement bytes');
                        self::assertSame($state->storedFile, $storage->storeCanonical($replacement, 1_700_000_000, false));
                    }
                }

                $source->exec("UPDATE notes SET body = '<p>Edited content</p>'");
            },
        );
        $storage = $this->mediaStorage($directory);
        $state->storedFile = $storage->storeCanonical($this->uploadImage($directory), 1_700_000_000, false);
        $pdo->exec('ALTER TABLE notes ADD COLUMN body TEXT');
        $pdo->exec('UPDATE notes SET body = ' . $pdo->quote('<img src="/media' . $state->storedFile . '">'));

        $validBackup = $manager->createNow(1_700_000_000);
        $state->mutate = true;

        try {
            $manager->createNow(1_700_000_001);
            self::fail('A concurrent media mutation must invalidate the full backup.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Media files changed during the backup; retry the backup.', $exception->getMessage());
        }

        self::assertSame($validBackup->path, $manager->latest()?->path);
        self::assertSame([$validBackup->path], glob($directory . '/backups/register-backup-*.zip.enc'));
        self::assertSame([], glob($directory . '/backups/.*-database.*'));
        self::assertSame([], glob($directory . '/backups/.*.zip*'));
        $state->mutate = false;
        $retry = $manager->createNow(1_700_000_002);
        self::assertSame($retry->path, $manager->latest()->path);
    }

    /** @return iterable<string, array{string}> */
    public static function concurrentMediaMutations(): iterable
    {
        yield 'delete after snapshot' => ['delete'];
        yield 'rename after snapshot' => ['redate'];
        yield 'replace the same filename after snapshot' => ['replace'];
    }

    public function testUpdateSnapshotRetentionIsIndependentFromFullBackupRetention(): void
    {
        [$manager, $directory] = $this->manager(retention: 2);
        $fullBackup = $manager->createNow(1_700_000_000);
        $first = $manager->createForUpdate(now: 1_700_000_001);
        $second = $manager->createForUpdate(now: 1_700_000_002);
        $third = $manager->createForUpdate(now: 1_700_000_003);

        self::assertFileExists($fullBackup->path);
        self::assertFileDoesNotExist($first->path);
        self::assertFileExists($second->path);
        self::assertFileExists($third->path);
        $snapshots = glob($directory . '/backups/updates/register-update-backup-*.zip.enc');
        self::assertIsArray($snapshots);
        self::assertCount(2, $snapshots);
        self::assertSame($fullBackup->path, $manager->latest()?->path);
    }

    public function testAutomaticScheduleAndRetentionKeepOnlyRecentArchives(): void
    {
        [$manager] = $this->manager(retention: 2);
        $firstTime = 1_700_000_000;

        $first = $manager->createIfDue($firstTime);
        self::assertNotNull($first);
        self::assertNull($manager->createIfDue($firstTime + BackupManager::AUTOMATIC_INTERVAL_SECONDS - 1));
        self::assertNotNull($manager->createIfDue($firstTime + BackupManager::AUTOMATIC_INTERVAL_SECONDS + 1));
        $latest = $manager->createNow($firstTime + 2 * BackupManager::AUTOMATIC_INTERVAL_SECONDS + 1);

        $archives = glob(\dirname($latest->path) . '/register-backup-*.zip.enc');
        self::assertIsArray($archives);
        self::assertCount(2, $archives);
        self::assertFileDoesNotExist($first->path);
        self::assertSame($latest->path, $manager->latest()?->path);
    }

    public function testRetentionPreservesTheBackupJustCreatedWhenTheClockMovesBackwards(): void
    {
        [$manager] = $this->manager(retention: 1);
        $future = $manager->createNow(1_800_000_000);
        $createdAfterClockCorrection = $manager->createNow(1_700_000_000);

        self::assertFileDoesNotExist($future->path);
        self::assertFileExists($createdAfterClockCorrection->path);
        self::assertSame($createdAfterClockCorrection->path, $manager->latest()?->path);
    }

    public function testLegacyPlainZipRemainsDiscoverableDuringMigration(): void
    {
        [$manager, $directory] = $this->manager(retention: 2);
        $backupDirectory = $directory . '/backups';
        mkdir($backupDirectory, 0700, true);
        $legacyPath = $backupDirectory . '/register-backup-20231114-221320-deadbeef.zip';
        file_put_contents($legacyPath, 'legacy zip');
        touch($legacyPath, 1_700_000_000);

        self::assertSame($legacyPath, $manager->latest()?->path);
        $created = $manager->createNow(1_700_000_001);

        self::assertFileExists($legacyPath);
        self::assertFileExists($created->path);
        self::assertEquals($created, $manager->latest());
    }

    public function testRemovesOnlyAbandonedGeneratedWorkFiles(): void
    {
        [$manager, $directory] = $this->manager(retention: 2);
        $backupDirectory = $directory . '/backups';
        if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Unable to create the test backup directory.');
        }

        $abandonedArchive  = $backupDirectory . '/.0123456789abcdef.zip';
        $abandonedEncryptedArchive = $backupDirectory . '/.0123456789abcdef.zip.enc';
        $abandonedSnapshot = $backupDirectory . '/.fedcba9876543210-database.sqlite';
        $unrelatedFile     = $backupDirectory . '/.keep';
        file_put_contents($abandonedArchive, 'partial archive');
        file_put_contents($abandonedEncryptedArchive, 'partial encrypted archive');
        file_put_contents($abandonedSnapshot, 'partial database');
        file_put_contents($unrelatedFile, 'keep');

        $manager->createNow(1_700_000_000);

        self::assertFileDoesNotExist($abandonedArchive);
        self::assertFileDoesNotExist($abandonedEncryptedArchive);
        self::assertFileDoesNotExist($abandonedSnapshot);
        self::assertFileExists($unrelatedFile);
    }

    public function testDoesNotWaitForAnotherBackupProcess(): void
    {
        [$manager, $directory] = $this->manager(retention: 2);
        $backupDirectory = $directory . '/backups';
        if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Unable to create the test backup directory.');
        }

        $lock = fopen($backupDirectory . '/.backup.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Unable to acquire the test backup lock.');
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Another backup is already in progress.');
            $manager->createNow(1_700_000_000);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testMissingStableKeyFailsWithoutPublishingPlaintextBackup(): void
    {
        [$manager, $directory] = $this->manager(retention: 2, encryptionSecret: 'short');

        try {
            $manager->createNow(1_700_000_000);
            self::fail('Backup creation must fail without a stable encryption key.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('stable secret', $runtimeException->getMessage());
        }

        $published = glob($directory . '/backups/register-backup-*');
        self::assertIsArray($published);
        self::assertSame([], $published);
        self::assertFileDoesNotExist($directory . '/backups/database.sqlite');
    }

    public function testIncludesBoundedContributorMaterialOnlyInsideEncryptedEnvelope(): void
    {
        $contributor = new class implements BackupContributorInterface {
            /** @return list<BackupEntry> */
            #[\Override]
            public function backupEntries(): array
            {
                return [new BackupEntry(
                    'extensions/example/private-recovery.json',
                    '{"secret":"extension recovery material"}',
                )];
            }
        };
        [$manager, $directory, $encryptor] = $this->manager(2, contributors: [$contributor]);
        $backup = $manager->createNow(1_700_000_000);
        $encrypted = file_get_contents($backup->path);
        self::assertIsString($encrypted);
        self::assertStringNotContainsString('extension recovery material', $encrypted);

        $decryptedPath = $directory . '/supplemental.zip';
        $encryptor->decryptFile($backup->path, $decryptedPath);
        $archive = file_get_contents($decryptedPath);
        self::assertIsString($archive);
        self::assertStringContainsString('extensions/example/private-recovery.json', $archive);
        self::assertStringContainsString('extension recovery material', $archive);
    }

    public function testRejectsSymbolicLinkBackupDirectory(): void
    {
        [$manager, $directory] = $this->manager(retention: 2);
        $target = $directory . '/redirected-backups';
        mkdir($target, 0700);
        if (!symlink($target, $directory . '/backups')) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        self::assertNull($manager->latest());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a real directory');
        $manager->createNow(1_700_000_000);
    }

    /**
     * @param list<BackupContributorInterface> $contributors
     * @param (\Closure(\PDO, string): void)|null $afterSnapshot
     * @return array{0:BackupManager,1:string,2:BackupEncryptor,3:\PDO}
     */
    private function manager(
        int    $retention,
        string $encryptionSecret = 'backup-test-secret-backup-test-secret-',
        array  $contributors = [],
        ?\Closure $afterSnapshot = null,
    ): array
    {
        $directory = $this->temporaryDirectory();
        $database  = $directory . '/source.sqlite';
        $pdo = new class('sqlite:' . $database, $afterSnapshot, $directory) extends \PDO {
            /** @param (\Closure(\PDO, string): void)|null $afterSnapshot */
            public function __construct(string $dsn, private readonly ?\Closure $afterSnapshot, private readonly string $root)
            {
                parent::__construct($dsn);
            }

            #[\Override]
            public function exec(string $statement): int|false
            {
                $result = parent::exec($statement);
                if (str_starts_with($statement, 'VACUUM INTO ')) {
                    $this->afterSnapshot?->__invoke($this, $this->root);
                }

                return $result;
            }
        };
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $pdo->exec("INSERT INTO notes (title) VALUES ('Saved note')");

        $mediaDirectory = $directory . '/media/nested';
        if (!mkdir($mediaDirectory, 0700, true) && !is_dir($mediaDirectory)) {
            throw new \RuntimeException('Unable to create the test media directory.');
        }

        file_put_contents($mediaDirectory . '/photo.webp', 'image bytes');

        $encryptor = new BackupEncryptor(
            new BackupEncryptionKeyProvider($encryptionSecret),
        );

        return [
            new BackupManager(
                new DatabaseSnapshotter($pdo, 'sqlite', '', $database, '', ''),
                $encryptor,
                new NullLogger(),
                new SecurityAuditLogger($directory . '/security-audit.jsonl', new SpamIdentityHasher(str_repeat('a', 32))),
                $directory . '/backups',
                $directory . '/media',
                $retention,
                'test-version',
                new MediaMutationTracker($directory . '/cache/media-mutations.lock'),
                ...$contributors,
            ),
            $directory,
            $encryptor,
            $pdo,
        ];
    }

    private function uploadImage(string $directory, string $suffix = ''): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', true);
        self::assertIsString($bytes);
        $path = $directory . '/upload-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, $bytes . $suffix);

        return new UploadedFile($path, 'image.png', 'image/png', null, true);
    }

    private function mediaStorage(string $directory): PostInplaceMediaStorage
    {
        $translator = new Translator([], 'en');

        return new PostInplaceMediaStorage(
            new PictureFileNameHelper($translator, 'png'),
            new PictureStorageQuota($translator, $directory . '/media', $directory . '/cache/quota.lock', 1_000_000),
            $translator, $directory . '/media', '', $directory . '/cache',
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/register_backup_test_' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create a temporary test directory.');
        }

        $this->temporaryDirectory = $directory;

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } elseif ($item instanceof \SplFileInfo) {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
