<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Stores privacy-preserving daily view aggregates without visitor identifiers. */
final class ContentViewSchema
{
    public const string TABLE_NAME = 'content_views_daily';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('content_type', 8)
                ->addInteger('content_id', true)
                ->addString('day', 10)
                ->addInteger('views', true)
                ->setPrimaryKey(['content_type', 'content_id', 'day'])
                ->addForeignKey(
                    'fk_content',
                    ['content_id'],
                    ContentSchema::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addIndex('content_idx', ['content_id'])
                ->addIndex('day_type_idx', ['day', 'content_type'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
