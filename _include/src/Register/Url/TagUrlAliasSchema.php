<?php

declare(strict_types = 1);

namespace Register\Url;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Tag aliases store identities, so repeated renames never create redirect chains. */
final class TagUrlAliasSchema
{
    public const string TABLE_NAME = 'tag_url_alias';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table->addString('slug', 255)
                ->addInteger('tag_id', true)
                ->setPrimaryKey(['slug'])
                ->addForeignKey('fk_tag_url_alias_tag', ['tag_id'], 'tags', ['id'], 'CASCADE')
                ->addIndex('tag_idx', ['tag_id']);
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
