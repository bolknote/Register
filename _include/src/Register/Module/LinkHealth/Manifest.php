<?php
/**
 * Broken-link inventory and repair.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentSchema;
use Register\Module\BaseModuleInstallerInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

final class Manifest implements BaseModuleInstallerInterface
{
    public const string TARGET_TABLE = 'register_link_target';

    public const string CONTENT_LINK_TABLE = 'register_content_link';

    public const string CHECK_TABLE = 'register_link_check';

    public const string REPAIR_TABLE = 'register_link_repair';

    public const string THROTTLE_TABLE = 'register_link_throttle';

    public const string INVENTORY_GENERATION_CONFIG_KEY = 'REGISTER_LINK_INVENTORY_GENERATION';

    public const string AUTO_REPAIR_CONFIG_KEY = 'REGISTER_LINK_AUTO_REPAIR';

    public const int INVENTORY_GENERATION = 1;

    #[\Override]
    public function getTitle(): string
    {
        return 'Link health';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Unique link inventory, background health checks, and safe Wayback repair.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0dev';
    }

    #[\Override]
    public function installFresh(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TARGET_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('url_hash', 64)
                ->addLongText('normalized_url', nullable: false)
                ->addString('kind', 16)
                ->addString('host', 255)
                ->addInteger('local_content_id', true, true, null)
                ->addString('health_status', 16, default: LinkHealthStatus::UNKNOWN->value)
                ->addInteger('http_status', true, true, null)
                ->addInteger('failure_count', true)
                ->addLongText('effective_url')
                ->addLongText('last_error')
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->addInteger('last_checked_at', true, true, null)
                ->addInteger('last_success_at', true, true, null)
                ->addInteger('next_check_at', true, true, null)
                ->addString('archive_status', 16, default: ArchiveStatus::UNCHECKED->value)
                ->addLongText('archive_url')
                ->addString('archive_timestamp', 14, true, null)
                ->addInteger('archive_checked_at', true, true, null)
                ->addUniqueIndex('url_hash_idx', ['url_hash'])
                ->addIndex('due_idx', ['kind', 'health_status', 'next_check_at'])
                ->addIndex('host_idx', ['host'])
                ->addIndex('local_content_idx', ['local_content_id'])
            ;
        });

        $dbLayer->createTable(self::CONTENT_LINK_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('source_content_id', true)
                ->addInteger('target_id', true)
                ->addLongText('original_href', nullable: false)
                ->addInteger('occurrence_count', true, default: 1)
                ->addInteger('content_revision', true, default: 1)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['source_content_id', 'target_id'])
                ->addIndex('target_source_idx', ['target_id', 'source_content_id'])
                ->addForeignKey(
                    'fk_source_content',
                    ['source_content_id'],
                    ContentSchema::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addForeignKey(
                    'fk_target',
                    ['target_id'],
                    self::TARGET_TABLE,
                    ['id'],
                    'CASCADE',
                )
            ;
        });

        $dbLayer->createTable(self::CHECK_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('target_id', true)
                ->addInteger('checked_at', true)
                ->addString('health_status', 16)
                ->addInteger('http_status', true, true, null)
                ->addLongText('effective_url')
                ->addLongText('error')
                ->addIndex('target_checked_idx', ['target_id', 'checked_at'])
                ->addIndex('checked_idx', ['checked_at'])
                ->addForeignKey(
                    'fk_target',
                    ['target_id'],
                    self::TARGET_TABLE,
                    ['id'],
                    'CASCADE',
                )
            ;
        });

        $dbLayer->createTable(self::REPAIR_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('target_id', true)
                ->addInteger('content_id', true)
                ->addLongText('old_url', nullable: false)
                ->addLongText('new_url', nullable: false)
                ->addInteger('occurrence_count', true)
                ->addInteger('revision_before', true)
                ->addInteger('revision_after', true)
                ->addInteger('repaired_at', true)
                ->addIndex('target_repaired_idx', ['target_id', 'repaired_at'])
                ->addIndex('content_repaired_idx', ['content_id', 'repaired_at'])
                ->addForeignKey(
                    'fk_target',
                    ['target_id'],
                    self::TARGET_TABLE,
                    ['id'],
                    'CASCADE',
                )
            ;
        });

        $dbLayer->createTable(self::THROTTLE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('service', 32)
                ->addInteger('next_request_at', true)
                ->setPrimaryKey(['service'])
            ;
        });

        $dbLayer->upsert(self::THROTTLE_TABLE)
            ->setKey('service', ':service')->setParameter('service', WaybackRequestThrottle::SERVICE)
            ->setValue('next_request_at', '0')
            ->execute()
        ;

        $dbLayer->upsert('config')
            ->setKey('name', ':name')->setParameter('name', self::INVENTORY_GENERATION_CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', (string)self::INVENTORY_GENERATION)
            ->execute()
        ;
        $dbLayer->upsert('config')
            ->setKey('name', ':name')->setParameter('name', self::AUTO_REPAIR_CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', '1')
            ->execute()
        ;
    }
}
