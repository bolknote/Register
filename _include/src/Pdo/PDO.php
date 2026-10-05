<?php
/**
 * PDO wrapper with query logging.
 *
 * Forked from https://github.com/filisko/pdo-plus
 * 1. Fixed a bug with PDO::query()
 * 2. Updated code to PHP 8.3
 *
 * @copyright 2023-2024 Roman Parpalak, based on code (c) 2021 Filis Futsarov
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

use PDO as NativePdo;
use Register\Core\Framework\StatefulServiceInterface;

class PDO extends NativePdo implements StatefulServiceInterface
{
    /** @var list<array{statement: string, template: string, time: float}> */
    protected array $log = [];

    /** @var array<string, callable(): void> */
    private array $afterCommitCallbacks = [];

    /** @var array<string, callable(): void> */
    private array $afterRollbackCallbacks = [];

    /** @var list<array{name: string, commit_keys: list<string>, rollback_keys: list<string>}> */
    private array $savepointCallbacks = [];

    private int $afterCommitSequence = 0;

    /** @var \Closure(): void|null */
    private ?\Closure $initializer = null;

    private bool $connected = false;

    private ?string $configuredDriver = null;

    /** @var list<callable(): void> */
    private array $connectionCallbacks = [];

    private ?\Throwable $initializationFailure = null;

    /**
     * {@inheritdoc}
     * @param array<mixed>|null $options
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter] ?string $passwd = null,
        ?array $options = null,
        bool $lazy = false,
    ) {
        $driver = strstr($dsn, ':', true);
        $this->configuredDriver = \is_string($driver) && \in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)
            ? $driver
            : null;
        if (!$lazy) {
            $start = microtime(true);
            parent::__construct($dsn, $username, $passwd, $options);
            $this->configureConnection($start);
        } else {
            $this->initializer = function () use ($dsn, $username, $passwd, $options): void {
                $start = microtime(true);
                parent::__construct($dsn, $username, $passwd, $options);
                $this->configureConnection($start);
            };
        }
    }

    private function configureConnection(float $startedAt): void
    {
        parent::setAttribute(self::ATTR_STATEMENT_CLASS, [PDOStatement::class, [$this]]);
        parent::setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
        $this->connected = true;
        $this->addLog('PDO connect', microtime(true) - $startedAt);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function addConnectionCallback(callable $callback): void
    {
        if ($this->connected) {
            $callback();
            return;
        }

        $this->connectionCallbacks[] = $callback;
    }

    private function initializeConnection(): void
    {
        if ($this->initializationFailure !== null) {
            throw $this->initializationFailure;
        }

        if ($this->connected) {
            return;
        }

        if ($this->initializer === null) {
            throw new \LogicException('A database connection initializer is missing.');
        }

        ($this->initializer)();
        $this->initializer = null;
        $callbacks = $this->connectionCallbacks;
        $this->connectionCallbacks = [];
        try {
            foreach ($callbacks as $callback) {
                $callback();
            }
        } catch (\Throwable $throwable) {
            $this->initializationFailure = $throwable;
            throw $throwable;
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function beginTransaction(): bool
    {
        $this->initializeConnection();
        $started = parent::beginTransaction();
        if ($started) {
            $this->afterCommitCallbacks = [];
            $this->afterRollbackCallbacks = [];
            $this->savepointCallbacks = [];
            $this->afterCommitSequence = 0;
        }

        return $started;
    }

    /**
     * Runs a side effect only after the surrounding database transaction is durable.
     *
     * Cache invalidation is the primary consumer: deleting a cache entry before COMMIT
     * lets a concurrent request repopulate it from the old database snapshot.
     *
     * @param callable(): void $callback
     */
    public function afterCommit(callable $callback): void
    {
        if (!$this->inTransaction()) {
            $callback();

            return;
        }

        $this->afterCommitCallbacks['callback_' . ++$this->afterCommitSequence] = $callback;
    }

    /**
     * Coalesces repeated work in one transaction, for example a bulk import
     * invalidating the same page-cache dependency thousands of times.
     *
     * @param callable(): void $callback
     */
    public function afterCommitOnce(string $key, callable $callback): bool
    {
        if ($key === '') {
            throw new \InvalidArgumentException('An after-commit callback key cannot be empty.');
        }

        if (!$this->inTransaction()) {
            $callback();

            return true;
        }

        $callbackKey = 'once_' . hash('sha256', $key);
        if (isset($this->afterCommitCallbacks[$callbackKey])) {
            return false;
        }

        $this->afterCommitCallbacks[$callbackKey] = $callback;

        return true;
    }

    /** @param callable(): void $callback */
    public function afterRollbackOnce(string $key, callable $callback): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('An after-rollback callback key cannot be empty.');
        }

        if (!$this->inTransaction()) {
            return;
        }

        $this->afterRollbackCallbacks['once_' . hash('sha256', $key)] ??= $callback;
    }

    #[\Override]
    public function commit(): bool
    {
        $this->initializeConnection();
        $committed = parent::commit();
        if (!$committed) {
            return false;
        }

        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        $this->afterRollbackCallbacks = [];
        $this->savepointCallbacks = [];
        $this->afterCommitSequence = 0;

        $this->runCallbacks($callbacks);

        return true;
    }

    #[\Override]
    public function rollBack(): bool
    {
        $this->initializeConnection();
        $rolledBack = false;
        try {
            $rolledBack = parent::rollBack();
        } finally {
            $callbacks = $rolledBack ? $this->afterRollbackCallbacks : [];
            $this->afterCommitCallbacks = [];
            $this->afterRollbackCallbacks = [];
            $this->savepointCallbacks = [];
            $this->afterCommitSequence = 0;
        }

        $this->runCallbacks($callbacks);

        return $rolledBack;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        // Selecting a SQL dialect is metadata, not a reason to open a connection on a cache hit.
        if (!$this->connected && $attribute === self::ATTR_DRIVER_NAME && $this->configuredDriver !== null) {
            return $this->configuredDriver;
        }

        $this->initializeConnection();
        return parent::getAttribute($attribute);
    }

    #[\Override]
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->initializeConnection();
        return parent::setAttribute($attribute, $value);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->connected && parent::inTransaction();
    }

    #[\Override]
    public function lastInsertId(?string $name = null): string|false
    {
        $this->initializeConnection();
        return parent::lastInsertId($name);
    }

    #[\Override]
    public function errorCode(): ?string
    {
        $this->initializeConnection();
        return parent::errorCode();
    }

    /** @return array<mixed> */
    #[\Override]
    public function errorInfo(): array
    {
        $this->initializeConnection();
        return parent::errorInfo();
    }

    /**
     * {@inheritdoc}
     * @param array<mixed> $options
     */
    #[\Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->initializeConnection();
        $statement = parent::prepare($query, $options);
        if ($statement === false || $statement instanceof PDOStatement) {
            return $statement;
        }

        throw new \LogicException('PDO returned an unexpected statement implementation.');
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        $this->initializeConnection();
        return parent::quote($string, $type);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function exec(string $statement): int|false
    {
        $this->initializeConnection();
        $start  = microtime(true);
        $result = parent::exec($statement);
        $this->addLog($statement, microtime(true) - $start);
        if ($result !== false) {
            $this->trackSavepointStatement($statement);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->initializeConnection();
        $start = microtime(true);

        // Here is a fix in this line.
        $result = parent::query(...\func_get_args());
        $this->addLog($query, microtime(true) - $start);

        if ($result === false || $result instanceof PDOStatement) {
            return $result;
        }

        throw new \LogicException('PDO returned an unexpected statement implementation.');
    }

    /**
     * Add query to logged queries.
     */
    public function addLog(string $statement, float $time, ?string $template = null): void
    {
        $this->log[] = [
            'statement' => $statement,
            'template'  => $template ?? $statement,
            'time'      => $time
        ];
    }

    /**
     * Return logged queries.
     * @return list<array{statement: string, template: string, time: float}>
     * @phpstan-impure
     */
    public function cleanLogs(): array
    {
        $result    = $this->log;
        $this->log = [];

        return $result;
    }

    /**
     * @return list<array{statement: string, template: string, time: float}>
     */
    public function getQueryLog(): array
    {
        return $this->log;
    }

    public function getQueryCount(): int
    {
        return \count($this->log);
    }

    /** @return array{count:int, total_seconds:float, slowest_seconds:float} */
    public function getQueryMetrics(): array
    {
        $total = 0.0;
        $slowest = 0.0;
        foreach ($this->log as $entry) {
            $total += $entry['time'];
            $slowest = max($slowest, $entry['time']);
        }

        return [
            'count' => \count($this->log),
            'total_seconds' => $total,
            'slowest_seconds' => $slowest,
        ];
    }

    #[\Override]
    public function clearState(): void
    {
        $this->log = [];
        if (!$this->inTransaction()) {
            $this->afterCommitCallbacks = [];
            $this->afterRollbackCallbacks = [];
            $this->savepointCallbacks = [];
            $this->afterCommitSequence = 0;
        }
    }

    private function trackSavepointStatement(string $statement): void
    {
        if (preg_match('/^\s*SAVEPOINT\s+([A-Za-z0-9_]+)\s*;?\s*$/iD', $statement, $matches) === 1) {
            $this->savepointCallbacks[] = [
                'name' => strtolower($matches[1]),
                'commit_keys' => array_keys($this->afterCommitCallbacks),
                'rollback_keys' => array_keys($this->afterRollbackCallbacks),
            ];

            return;
        }

        if (preg_match('/^\s*ROLLBACK\s+TO(?:\s+SAVEPOINT)?\s+([A-Za-z0-9_]+)\s*;?\s*$/iD', $statement, $matches) === 1) {
            $index = $this->savepointIndex($matches[1]);
            if ($index === null) {
                return;
            }

            $this->afterCommitCallbacks = array_intersect_key(
                $this->afterCommitCallbacks,
                array_fill_keys($this->savepointCallbacks[$index]['commit_keys'], true),
            );
            $rollbackKeys = array_fill_keys($this->savepointCallbacks[$index]['rollback_keys'], true);
            $rolledBackCallbacks = array_diff_key($this->afterRollbackCallbacks, $rollbackKeys);
            $this->afterRollbackCallbacks = array_intersect_key($this->afterRollbackCallbacks, $rollbackKeys);
            $this->savepointCallbacks = \array_slice($this->savepointCallbacks, 0, $index + 1);
            $this->runCallbacks($rolledBackCallbacks);

            return;
        }

        if (preg_match('/^\s*RELEASE\s+SAVEPOINT\s+([A-Za-z0-9_]+)\s*;?\s*$/iD', $statement, $matches) === 1) {
            $index = $this->savepointIndex($matches[1]);
            if ($index !== null) {
                \array_splice($this->savepointCallbacks, $index, 1);
            }
        }
    }

    private function savepointIndex(string $name): ?int
    {
        $name = strtolower($name);
        for ($index = \count($this->savepointCallbacks) - 1; $index >= 0; --$index) {
            if ($this->savepointCallbacks[$index]['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string, callable(): void> $callbacks */
    private function runCallbacks(array $callbacks): void
    {
        $firstFailure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $throwable) {
                $firstFailure ??= $throwable;
            }
        }

        if ($firstFailure instanceof \Throwable) {
            throw $firstFailure;
        }
    }

}
