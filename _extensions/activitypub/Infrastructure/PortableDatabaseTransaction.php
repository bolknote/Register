<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

/** Portable transaction/savepoint wrapper that composes with Register's test and product transactions. */
final class PortableDatabaseTransaction
{
    private int $savepointSequence = 0;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function run(\Closure $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint       = 'register_ap_' . ++$this->savepointSequence;

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $throwable;
        }
    }
}
