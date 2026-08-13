<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Extensions\ManifestTrait;
use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

class Manifest implements ManifestInterface
{
    use ManifestTrait;

    public const string SALT_CONFIG_KEY = 'REGISTER_ANALYTICS_SALT';

    #[\Override]
    public function getTitle(): string
    {
        return 'Analytics';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Privacy-conscious daily page-view and feed-reader statistics.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '3.0';
    }

    #[\Override]
    public function install(DbLayer $dbLayer, Container $container, ?string $currentVersion): void
    {
        unset($container, $currentVersion);

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

        $dbLayer->insert('config')
            ->setValue('name', ':name')->setParameter('name', self::SALT_CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', bin2hex(random_bytes(32)))
            ->onConflictDoNothing('name')
            ->execute()
        ;
    }

    #[\Override]
    public function uninstall(DbLayer $dbLayer, Container $container): void
    {
        unset($dbLayer, $container);

        throw new \LogicException('The Analytics module is part of Register and cannot be uninstalled.');
    }
}
