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
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupEncryptor;
use Register\Backup\BackupManager;
use Register\Backup\DatabaseSnapshotter;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Security\Audit\SecurityAuditLogger;

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

    /** @return array{0:BackupManager,1:string,2:BackupEncryptor} */
    private function manager(int $retention, string $encryptionSecret = 'backup-test-secret-backup-test-secret-'): array
    {
        $directory = $this->temporaryDirectory();
        $database  = $directory . '/source.sqlite';
        $pdo       = new \PDO('sqlite:' . $database);
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
            ),
            $directory,
            $encryptor,
        ];
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
