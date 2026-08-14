<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Register\Backup\DatabaseSnapshotter;

final class DatabaseSnapshotterTest extends Unit
{
    private ?string $temporaryDirectory = null;

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== null) {
            $this->deleteDirectory($this->temporaryDirectory);
        }
    }

    public function testCreatesConsistentStandaloneSqliteSnapshot(): void
    {
        $directory = $this->temporaryDirectory();
        $database  = $directory . '/source.sqlite';
        $pdo       = new \PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $pdo->exec("INSERT INTO notes (title) VALUES ('Back me up')");

        $snapshot = (new DatabaseSnapshotter($pdo, 'sqlite', '', $database, '', ''))
            ->create($directory . '/snapshot');

        self::assertSame('sqlite', $snapshot->driver);
        self::assertSame('database.sqlite', $snapshot->archiveName);
        self::assertFileExists($snapshot->path);

        $restored = new \PDO('sqlite:' . $snapshot->path);
        $statement = $restored->query('SELECT title FROM notes');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query the SQLite snapshot.');
        }

        self::assertSame('Back me up', $statement->fetchColumn());
    }

    public function testRejectsUnsupportedDatabaseDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseSnapshotter(new \PDO('sqlite::memory:'), 'oracle', '', '', '', '');
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/register_snapshot_test_' . bin2hex(random_bytes(8));
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
