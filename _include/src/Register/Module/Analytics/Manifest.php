<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Module\BaseModuleInstallerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

class Manifest implements BaseModuleInstallerInterface
{
    public const string SALT_CONFIG_KEY = 'REGISTER_ANALYTICS_SALT';

    #[\Override]
    public function getTitle(): string
    {
        return 'Analytics';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak and Evgeny Stepanischev';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Daily page-view and feed-reader statistics without raw IP or User-Agent storage.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '4.0';
    }

    #[\Override]
    public function installFresh(DbLayer $dbLayer): void
    {
        $dbLayer->createTable('register_analytics_daily', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('channel', 64)
                ->addInteger('hits', true)
                ->addInteger('unique_count', true)
                ->setPrimaryKey(['day', 'channel'])
                ->addIndex('channel_day_idx', ['channel', 'day'])
            ;
        });

        $dbLayer->createTable('register_analytics_visitor', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('channel', 64)
                ->addString('fingerprint', 64)
                ->setPrimaryKey(['day', 'channel', 'fingerprint'])
                ->addIndex('day_idx', ['day'])
            ;
        });

        AnalyticsSchema::createEventStorage($dbLayer);

        $dbLayer->insert('config')
            ->setValue('name', ':name')->setParameter('name', self::SALT_CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', bin2hex(random_bytes(32)))
            ->onConflictDoNothing('name')
            ->execute()
        ;
    }

}
