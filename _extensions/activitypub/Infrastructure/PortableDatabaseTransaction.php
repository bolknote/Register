<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DatabaseTransaction;

/** Portable transaction/savepoint wrapper that composes with Register's test and product transactions. */
final readonly class PortableDatabaseTransaction
{
    private DatabaseTransaction $transaction;

    public function __construct(\PDO $pdo)
    {
        $this->transaction = new DatabaseTransaction($pdo);
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function run(\Closure $operation): mixed
    {
        return $this->transaction->run($operation);
    }
}
