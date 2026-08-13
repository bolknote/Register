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
 * @package S2
 */

declare(strict_types = 1);

namespace S2\Cms\Pdo;

use PDO as NativePdo;
use S2\Cms\Framework\StatefulServiceInterface;

class PDO extends NativePdo implements StatefulServiceInterface
{
    /** @var list<array{statement: string, time: float}> */
    protected array $log = [];

    /**
     * {@inheritdoc}
     * @param array<mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $passwd = null, ?array $options = null)
    {
        $start = microtime(true);
        parent::__construct($dsn, $username, $passwd, $options);
        $this->setAttribute(self::ATTR_STATEMENT_CLASS, [PDOStatement::class, [$this]]);
        $this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
        $this->addLog('PDO connect', microtime(true) - $start);
    }

    public function addConnectionCallback(callable $callback): void
    {
        $callback();
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function beginTransaction(): bool
    {
        return parent::beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getAttribute(int $attribute): mixed
    {
        return parent::getAttribute($attribute);
    }

    /**
     * {@inheritdoc}
     * @param array<mixed> $options
     */
    #[\Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
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
        return parent::quote($string, $type);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function exec(string $statement): int|false
    {
        $start  = microtime(true);
        $result = parent::exec($statement);
        $this->addLog($statement, microtime(true) - $start);
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
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
    public function addLog(string $statement, float $time): void
    {
        $this->log[] = [
            'statement' => $statement,
            'time'      => $time
        ];
    }

    /**
     * Return logged queries.
     * @return list<array{statement: string, time: float}>
     */
    public function cleanLogs(): array
    {
        $result    = $this->log;
        $this->log = [];

        return $result;
    }

    public function getQueryCount(): int
    {
        return \count($this->log);
    }

    #[\Override]
    public function clearState(): void
    {
        $this->log = [];
    }

}
