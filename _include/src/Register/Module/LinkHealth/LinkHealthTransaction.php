<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

/** Keeps result persistence and its durable follow-up job in one short database transaction. */
final readonly class LinkHealthTransaction
{
    private const string SAVEPOINT = 'register_link_health_result';

    public function __construct(private \PDO $pdo)
    {
    }

    /** @param \Closure(): void $operation */
    public function run(\Closure $operation): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (\Throwable $throwable) {
            if ($ownsTransaction) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            throw $throwable;
        }
    }
}
