<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

final readonly class DatabaseSnapshotter
{
    public function __construct(
        private \PDO  $pdo,
        private string $driver,
        private string $host,
        private string $database,
        private string $username,
        private string $password,
    ) {
        if (!\in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Unsupported backup database driver: ' . $driver);
        }
    }

    public function create(string $targetBase): DatabaseSnapshot
    {
        return match ($this->driver) {
            'sqlite' => $this->createSqliteSnapshot($targetBase . '.sqlite'),
            'mysql'  => $this->createMysqlDump($targetBase . '.sql'),
            'pgsql'  => $this->createPostgresDump($targetBase . '.sql'),
            default  => throw new \LogicException('Unsupported backup database driver.'),
        };
    }

    private function createSqliteSnapshot(string $targetPath): DatabaseSnapshot
    {
        $this->assertNewTarget($targetPath);
        $quotedPath = $this->pdo->quote($targetPath);
        if ($quotedPath === false || $this->pdo->exec('VACUUM INTO ' . $quotedPath) === false) {
            throw new \RuntimeException('SQLite could not create a consistent backup snapshot.');
        }

        return new DatabaseSnapshot($targetPath, 'database.sqlite', $this->driver);
    }

    private function createMysqlDump(string $targetPath): DatabaseSnapshot
    {
        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--hex-blob',
            '--default-character-set=utf8mb4',
        ];
        if ($this->host !== '') {
            $command[] = '--host=' . $this->host;
        }

        if ($this->username !== '') {
            $command[] = '--user=' . $this->username;
        }

        $command[] = '--';
        $command[] = $this->requiredDatabaseName();

        $this->runDumpCommand($command, $targetPath, 'MYSQL_PWD');

        return new DatabaseSnapshot($targetPath, 'database.sql', $this->driver);
    }

    private function createPostgresDump(string $targetPath): DatabaseSnapshot
    {
        $command = [
            'pg_dump',
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
        ];
        if ($this->host !== '') {
            $command[] = '--host=' . $this->host;
        }

        if ($this->username !== '') {
            $command[] = '--username=' . $this->username;
        }

        $command[] = '--dbname=' . $this->requiredDatabaseName();

        $this->runDumpCommand($command, $targetPath, 'PGPASSWORD');

        return new DatabaseSnapshot($targetPath, 'database.sql', $this->driver);
    }

    /** @param list<string> $command */
    private function runDumpCommand(array $command, string $targetPath, string $passwordVariable): void
    {
        $this->assertNewTarget($targetPath);
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException('Database backups require proc_open for this database driver.');
        }

        $environment = [];
        foreach (getenv() as $name => $value) {
            $environment[$name] = $value;
        }

        if ($this->password !== '') {
            $environment[$passwordVariable] = $this->password;
        }

        $pipes = [];
        try {
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $targetPath, 'wb'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true],
            );
        } catch (\Throwable $throwable) {
            $this->removeIncompleteFile($targetPath);
            $exceptionCode = $throwable->getCode();
            throw new \RuntimeException(
                'Unable to start the database dump utility.',
                \is_int($exceptionCode) ? $exceptionCode : 0,
                previous: $throwable,
            );
        }

        if (!\is_resource($process)) {
            $this->removeIncompleteFile($targetPath);
            throw new \RuntimeException('Unable to start the database dump utility.');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($targetPath)) {
            $this->removeIncompleteFile($targetPath);
            $details = \is_string($stderr) ? trim($stderr) : '';
            if ($details !== '') {
                $details = ' ' . substr($details, 0, 2000);
            }

            throw new \RuntimeException('The database dump utility failed.' . $details);
        }
    }

    private function requiredDatabaseName(): string
    {
        if ($this->database === '') {
            throw new \RuntimeException('The configured database name is empty.');
        }

        return $this->database;
    }

    private function assertNewTarget(string $targetPath): void
    {
        if (file_exists($targetPath)) {
            throw new \RuntimeException('Refusing to overwrite an existing database snapshot.');
        }
    }

    private function removeIncompleteFile(string $targetPath): void
    {
        if (is_file($targetPath)) {
            register_call_without_warnings(static fn(): bool => unlink($targetPath));
        }
    }
}
