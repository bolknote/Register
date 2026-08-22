<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class ContentTagSchema
{
    public const string TABLE_NAME = 'content_tag';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('content_type', 8)
                ->addInteger('content_id', true)
                ->addInteger('tag_id', true)
                ->addIndex('tag_content_idx', ['tag_id', 'content_type', 'content_id'])
                ->addForeignKey(
                    'fk_tag',
                    ['tag_id'],
                    'tags',
                    ['id'],
                    'CASCADE',
                )
            ;
        });
        // A named unique index is created explicitly because SQLite discards names of UNIQUE
        // constraints declared inside CREATE TABLE, which makes schema verification ambiguous.
        $dbLayer->addIndex(
            self::TABLE_NAME,
            'content_tag_idx',
            ['content_type', 'content_id', 'tag_id'],
            true,
        );
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
