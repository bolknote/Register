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

/** Defines the clean, shared storage contract for posts and permanent pages. */
final class ContentSchema
{
    public const string TABLE_NAME = 'content';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('content_type', 8)
                ->addInteger('parent_id', true, true, null)
                ->addString('slug_scope', 64)
                ->addString('slug', 255)
                ->addString('title', 255)
                ->addText('excerpt', nullable: false)
                ->addLongText('body', nullable: false)
                ->addString('meta_keywords', 255)
                ->addString('meta_description', 255)
                ->addInteger('created_at', true)
                ->addInteger('published_at', true, true, null)
                ->addInteger('updated_at', true)
                ->addInteger('revision', true, default: 1)
                ->addInteger('sort_order', true)
                ->addBoolean('published')
                ->addBoolean('featured')
                ->addBoolean('comments_enabled', default: true)
                ->addString('date_label', 255)
                ->addString('series', 255)
                ->addString('template', 30)
                ->addInteger('author_id', true, true, null)
                ->addForeignKey(
                    'fk_parent',
                    ['parent_id'],
                    self::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addForeignKey(
                    'fk_author',
                    ['author_id'],
                    'users',
                    ['id'],
                    'SET NULL',
                )
                ->addIndex('type_slug_idx', ['content_type', 'slug'])
                ->addIndex('type_parent_sort_idx', ['content_type', 'parent_id', 'sort_order', 'published'])
                ->addIndex('type_publication_idx', ['content_type', 'published', 'published_at'])
                ->addIndex('type_featured_idx', ['content_type', 'featured', 'published_at'])
                ->addIndex('type_series_idx', ['content_type', 'series'])
                ->addIndex('author_idx', ['author_id'])
                ->addIndex('template_idx', ['template'])
            ;
        });
        // Root content shares the "root" scope. Nested pages use "page:<parent id>". This portable
        // key enforces post/root-page collisions and sibling-page uniqueness on every supported DB.
        $dbLayer->addIndex(
            self::TABLE_NAME,
            'slug_scope_idx',
            ['slug_scope', 'slug'],
            true,
        );
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
