<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

/** An atomic operation that also composes with an existing transaction. */
final readonly class DatabaseTransaction
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $ownsTransaction = !$this->transactionActive();
        $savepoint = 'register_' . bin2hex(random_bytes(12));
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
        } catch (\Throwable $exception) {
            if ($this->transactionActive()) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }

            throw $exception;
        }
    }

    /** @phpstan-impure The connection can change state while an operation runs. */
    private function transactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }
}
