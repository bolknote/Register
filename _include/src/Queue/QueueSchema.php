<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

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
