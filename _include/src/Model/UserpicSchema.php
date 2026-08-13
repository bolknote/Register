<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

final class UserpicSchema
{
    public const string TABLE_NAME = 'userpics';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('storage_key', 255)
                ->addString('content_hash', 64)
                ->addString('mime_type', 50)
                ->addInteger('width', true)
                ->addInteger('height', true)
                ->addInteger('byte_size', true)
                ->addText('source_url')
                ->addInteger('created_time', true)
                ->addUniqueIndex('storage_key_idx', ['storage_key'])
                ->addUniqueIndex('content_hash_idx', ['content_hash'])
            ;
        });
    }

    public static function addCommentReferenceToDefinition(SchemaBuilderInterface $table): void
    {
        $table
            ->addInteger('userpic_id', true, nullable: true, default: null)
            ->addForeignKey(
                'fk_userpic',
                ['userpic_id'],
                self::TABLE_NAME,
                ['id'],
                'SET NULL',
            )
            ->addIndex('userpic_idx', ['userpic_id'])
        ;
    }

    public static function addCommentReference(DbLayer $dbLayer, string $commentTable, string $afterField): void
    {
        $dbLayer->addField(
            $commentTable,
            'userpic_id',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
            null,
            $afterField,
        );
        $dbLayer->addForeignKey(
            $commentTable,
            'fk_userpic',
            ['userpic_id'],
            self::TABLE_NAME,
            ['id'],
            'SET NULL',
        );
        $dbLayer->addIndex($commentTable, 'userpic_idx', ['userpic_id']);
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
