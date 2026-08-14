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
use Register\Backup\BackupManager;
use Register\Backup\DatabaseSnapshotter;

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
        [$manager, $directory] = $this->manager(retention: 2);
        $createdAt = 1_700_000_000;

        $backup = $manager->createNow($createdAt);

        self::assertFileExists($backup->path);
        self::assertSame($createdAt, $backup->createdAt);
        self::assertMatchesRegularExpression('/^register-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.zip$/D', $backup->name);

        $rawArchive = file_get_contents($backup->path);
        self::assertIsString($rawArchive);
        self::assertStringStartsWith("PK\x03\x04", $rawArchive);
        self::assertStringContainsString('manifest.json', $rawArchive);
        self::assertStringContainsString('media/nested/photo.webp', $rawArchive);
        self::assertStringContainsString('image bytes', $rawArchive);

        $zipClassName = 'ZipArchive';
        if (class_exists($zipClassName)) {
            $zip      = new $zipClassName();
            $zipClass = new \ReflectionObject($zip);
            self::assertTrue($zipClass->getMethod('open')->invoke($zip, $backup->path));
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

        $archives = glob(\dirname($latest->path) . '/register-backup-*.zip');
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

    /** @return array{0:BackupManager,1:string} */
    private function manager(int $retention): array
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

        return [
            new BackupManager(
                new DatabaseSnapshotter($pdo, 'sqlite', '', $database, '', ''),
                new NullLogger(),
                $directory . '/backups',
                $directory . '/media',
                $retention,
                'test-version',
            ),
            $directory,
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
            if ($item instanceof \SplFileInfo && $item->isDir()) {
                rmdir($item->getPathname());
            } elseif ($item instanceof \SplFileInfo) {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
