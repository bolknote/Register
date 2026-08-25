<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class QueueSchema
{
    public const string LEASE_TABLE = 'background_leases';

    public const string RUNNER_LEASE = 'queue-runner';

    public static function createRunnerLeaseStorage(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::LEASE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('name', 80)
                ->addString('owner', 64)
                ->addInteger('expires_at', true)
                ->setPrimaryKey(['name'])
            ;
        });

        self::ensureRunnerLease($dbLayer);
    }

    /** Restores the singleton row after a data migration deliberately clears stale leases. */
    public static function ensureRunnerLease(DbLayer $dbLayer): void
    {
        $dbLayer
            ->insert(self::LEASE_TABLE)
            ->setValue('name', ':name')->setParameter('name', self::RUNNER_LEASE)
            ->setValue('owner', ':owner')->setParameter('owner', '')
            ->setValue('expires_at', ':expires_at')->setParameter('expires_at', 0)
            ->onConflictDoNothing('name')
            ->execute()
        ;
    }
}
