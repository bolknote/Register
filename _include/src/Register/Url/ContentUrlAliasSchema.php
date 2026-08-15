<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use Register\Content\ContentSchema;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

/** Stores historical paths without coupling them to the current canonical slug. */
final class ContentUrlAliasSchema
{
    public const string TABLE_NAME = 'content_url_alias';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('path', 255)
                ->addInteger('content_id', true)
                ->setPrimaryKey(['path'])
                ->addForeignKey(
                    'fk_content_url_alias_content',
                    ['content_id'],
                    ContentSchema::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addIndex('content_idx', ['content_id'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
