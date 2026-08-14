<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

/**
 * A database-backed global lease. Unlike flock(), this serializes runners on every application node.
 */
final class QueueRunnerLease
{
    private ?string $owner = null;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dbPrefix,
    ) {
    }

    public function acquire(int $leaseSeconds): bool
    {
        if ($leaseSeconds < 1) {
            throw new \InvalidArgumentException('Queue runner lease duration must be positive.');
        }

        if ($this->owner !== null) {
            throw new \LogicException('The queue runner lease is already acquired.');
        }

        $owner      = bin2hex(random_bytes(32));
        $now        = QueueDatabaseClock::timestampExpression($this->pdo);
        $statement  = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . QueueSchema::LEASE_TABLE
            . ' SET owner = :owner, expires_at = ' . $now . ' + :lease_seconds'
            . ' WHERE name = :name AND expires_at <= ' . $now
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue runner lease acquisition query.');
        }

        $statement->execute([
            'owner'         => $owner,
            'lease_seconds' => $leaseSeconds,
            'name'          => QueueSchema::RUNNER_LEASE,
        ]);
        if ($statement->rowCount() !== 1) {
            return false;
        }

        $this->owner = $owner;
        return true;
    }

    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        $owner       = $this->owner;
        $this->owner = null;

        $statement   = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . QueueSchema::LEASE_TABLE
            . ' SET owner = :empty_owner, expires_at = 0 WHERE name = :name AND owner = :owner'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue runner lease release query.');
        }

        $statement->execute([
            'empty_owner' => '',
            'name'        => QueueSchema::RUNNER_LEASE,
            'owner'       => $owner,
        ]);
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
            // Destructors and shutdown cleanup must not introduce a second failure.
        }
    }

}
