<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

final readonly class QueueMonitor
{
    public function __construct(private \PDO $pdo, private string $dbPrefix)
    {
    }

    /**
     * @return array{
     *     total:int,
     *     ready:int,
     *     delayed:int,
     *     failed:int,
     *     oldest_ready_age:int|null,
     *     runner_active:bool,
     *     runner_lease_expires_at:int
     * }
     */
    public function status(?int $now = null): array
    {
        $now ??= time();
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS queue_total, '
            . 'COALESCE(SUM(CASE WHEN failed_at IS NULL AND available_at <= :ready_now THEN 1 ELSE 0 END), 0) AS queue_ready, '
            . 'COALESCE(SUM(CASE WHEN failed_at IS NULL AND available_at > :delayed_now THEN 1 ELSE 0 END), 0) AS queue_delayed, '
            . 'COALESCE(SUM(CASE WHEN failed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS queue_failed, '
            . 'MIN(CASE WHEN failed_at IS NULL AND available_at <= :oldest_now THEN created_at ELSE NULL END) AS oldest_ready_at '
            . 'FROM ' . $this->dbPrefix . 'queue'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue status query.');
        }

        $statement->execute([
            'ready_now'   => $now,
            'delayed_now' => $now,
            'oldest_now'  => $now,
        ]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new \RuntimeException('Unable to fetch queue status.');
        }

        $databaseNow    = QueueDatabaseClock::timestampExpression($this->pdo);
        $leaseStatement = $this->pdo->prepare(
            'SELECT expires_at, CASE WHEN owner <> :empty_owner AND expires_at > ' . $databaseNow
            . ' THEN 1 ELSE 0 END AS active FROM ' . $this->dbPrefix . QueueSchema::LEASE_TABLE
            . ' WHERE name = :name'
        );
        if ($leaseStatement === false) {
            throw new \RuntimeException('Unable to prepare the queue runner lease status query.');
        }

        $leaseStatement->execute([
            'empty_owner' => '',
            'name'        => QueueSchema::RUNNER_LEASE,
        ]);
        $lease = $leaseStatement->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($lease)) {
            throw new \RuntimeException('Unable to fetch the queue runner lease status.');
        }

        $oldestReadyAt  = $this->nullableIntegerField($row, 'oldest_ready_at');
        $leaseExpiresAt = $this->integerField($lease, 'expires_at');

        return [
            'total'                   => $this->integerField($row, 'queue_total'),
            'ready'                   => $this->integerField($row, 'queue_ready'),
            'delayed'                 => $this->integerField($row, 'queue_delayed'),
            'failed'                  => $this->integerField($row, 'queue_failed'),
            'oldest_ready_age'        => $oldestReadyAt === null ? null : max(0, $now - $oldestReadyAt),
            'runner_active'           => $this->integerField($lease, 'active') === 1,
            'runner_lease_expires_at' => $leaseExpiresAt,
        ];
    }

    /** @param array<string, mixed> $row */
    private function integerField(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!\is_int($value) && (!\is_string($value) || !ctype_digit($value))) {
            throw new \UnexpectedValueException(\sprintf('Queue status must contain an integer "%s" field.', $field));
        }

        return (int)$value;
    }

    /** @param array<string, mixed> $row */
    private function nullableIntegerField(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : $this->integerField($row, $field);
    }
}
