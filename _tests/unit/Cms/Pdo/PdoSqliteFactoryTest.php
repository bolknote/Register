<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use Codeception\Test\Unit;
use S2\Cms\Pdo\PdoSqliteFactory;

final class PdoSqliteFactoryTest extends Unit
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    #[\Override]
    protected function _after(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testConfiguresSqliteForOverlappingRequestAndShutdownAccess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's2_sqlite_');
        self::assertIsString($path);
        unlink($path);

        $this->temporaryFiles = [$path, $path . '-shm', $path . '-wal'];
        $pdo = PdoSqliteFactory::create($path, false);

        $journalMode = $pdo->query('PRAGMA journal_mode');
        self::assertNotFalse($journalMode);
        self::assertSame('wal', $journalMode->fetchColumn());

        $busyTimeout = $pdo->query('PRAGMA busy_timeout');
        self::assertNotFalse($busyTimeout);
        self::assertSame(60000, $busyTimeout->fetchColumn());

        $foreignKeys = $pdo->query('PRAGMA foreign_keys');
        self::assertNotFalse($foreignKeys);
        self::assertSame(1, $foreignKeys->fetchColumn());

        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($path);
            self::assertIsInt($permissions);
            self::assertSame(0600, $permissions & 0777);
        }
    }

    public function testRestrictsAnExistingDatabaseFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register_sqlite_permissions_');
        self::assertIsString($path);
        $this->temporaryFiles = [$path, $path . '-shm', $path . '-wal'];

        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertTrue(chmod($path, 0666));
        }

        PdoSqliteFactory::create($path, false);

        if (DIRECTORY_SEPARATOR !== '\\') {
            clearstatcache(false, $path);
            $permissions = fileperms($path);
            self::assertIsInt($permissions);
            self::assertSame(0600, $permissions & 0777);
        }
    }
}
