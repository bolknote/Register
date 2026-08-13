<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

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

    /**
     * Copies the two inherited relation tables into the Register relation without deleting them.
     * Keeping the legacy tables makes this migration independently reversible.
     */
    public static function copyLegacyRelations(DbLayer $dbLayer): void
    {
        $legacyRelations = [
            ContentType::PAGE->value => ['article_tag', 'article_id'],
            ContentType::POST->value => ['s2_blog_post_tag', 'post_id'],
        ];

        foreach ($legacyRelations as $contentType => [$table, $contentColumn]) {
            if (!$dbLayer->tableExists($table)) {
                continue;
            }

            $relations = $dbLayer
                ->select($contentColumn . ' AS content_id', 'tag_id')
                ->from($table)
                ->execute()
                ->fetchAssocAll()
            ;
            foreach ($relations as $relation) {
                $dbLayer
                    ->insert(self::TABLE_NAME)
                    ->values([
                        'content_type' => ':content_type',
                        'content_id'   => ':content_id',
                        'tag_id'       => ':tag_id',
                    ])
                    ->onConflictDoNothing('content_type', 'content_id', 'tag_id')
                    ->execute([
                        'content_type' => $contentType,
                        'content_id'   => (int)$relation['content_id'],
                        'tag_id'       => (int)$relation['tag_id'],
                    ])
                ;
            }
        }
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
