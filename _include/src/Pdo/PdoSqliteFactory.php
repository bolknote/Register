<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

class PdoSqliteFactory
{
    public static function create(string $dbFilename, bool $persistentConnection): PDO
    {
        if (!file_exists($dbFilename)) {
            register_call_without_warnings(static fn(): bool => touch($dbFilename));
            if (!file_exists($dbFilename)) {
                throw new \RuntimeException("Unable to create new database file '" . $dbFilename . "'. Permission denied. Please allow write permissions for the '" . \dirname($dbFilename) . "' directory.");
            }
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !register_call_without_warnings(static fn(): bool => chmod($dbFilename, 0600))) {
            throw new \RuntimeException("Unable to secure database file '" . $dbFilename . "' with mode 0600.");
        }

        if (!is_readable($dbFilename)) {
            throw new \RuntimeException("Unable to open database '" . $dbFilename . "' for reading. Permission denied");
        }

        if (!is_writable($dbFilename)) {
            throw new \RuntimeException("Unable to open database '" . $dbFilename . "' for writing. Permission denied");
        }

        if (!is_writable(\dirname($dbFilename))) {
            throw new \RuntimeException("Unable to write files in the '" . \dirname($dbFilename) . "' directory. Permission denied");
        }

        if ($persistentConnection) {
            $pdo = new PDO('sqlite:' . $dbFilename, "", "", [\PDO::ATTR_PERSISTENT => true]);
        } else {
            $pdo = new PDO('sqlite:' . $dbFilename);
        }

        // A new request may overlap the previous request's shutdown phase. WAL keeps readers
        // moving, while the busy timeout gives short competing writes time to serialize.
        $pdo->exec('PRAGMA busy_timeout = 60000;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');

        foreach ([$dbFilename . '-wal', $dbFilename . '-shm'] as $sqliteSidecar) {
            if (is_file($sqliteSidecar)) {
                register_call_without_warnings(static fn(): bool => chmod($sqliteSidecar, 0600));
            }
        }

        return $pdo;
    }
}
