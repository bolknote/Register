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

/** Stores editor-owned media and its use by posts. */
final class ContentMediaSchema
{
    public const string FILE_TABLE = 'content_media_file';

    public const string USAGE_TABLE = 'content_media_usage';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::FILE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('original_name', 255)
                ->addString('normalized_name', 255)
                ->addString('storage_path', 255)
                ->addString('mime_type', 127)
                ->addString('kind', 8)
                ->addInteger('byte_size', true)
                ->addInteger('width', true, true, null)
                ->addInteger('height', true, true, null)
                ->addInteger('uploaded_by', true, true, null)
                ->addInteger('usage_count', true)
                ->addBoolean('pending', default: true)
                ->addInteger('created_at', true)
                ->addForeignKey(
                    'fk_uploader',
                    ['uploaded_by'],
                    'users',
                    ['id'],
                    'SET NULL',
                )
                ->addIndex('name_idx', ['normalized_name', 'kind', 'id'])
                ->addIndex('unused_idx', ['usage_count', 'pending', 'created_at'])
            ;
        });
        $dbLayer->addIndex(
            self::FILE_TABLE,
            'storage_path_idx',
            ['storage_path'],
            true,
        );

        $dbLayer->createTable(self::USAGE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('post_id', true)
                ->addInteger('media_id', true)
                ->setPrimaryKey(['post_id', 'media_id'])
                ->addForeignKey(
                    'fk_post',
                    ['post_id'],
                    ContentSchema::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addForeignKey(
                    'fk_media',
                    ['media_id'],
                    self::FILE_TABLE,
                    ['id'],
                    'CASCADE',
                )
                ->addIndex('media_idx', ['media_id', 'post_id'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::USAGE_TABLE);
        $dbLayer->dropTable(self::FILE_TABLE);
    }
}
