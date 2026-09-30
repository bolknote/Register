<?php

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class PostCreateOperationSchema
{
    public const string TABLE_NAME = 'post_create_operation';

    public static function create(DbLayer $db): void
    {
        $db->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table->addString('operation_key', 64)->addString('request_hash', 64)
                ->addInteger('post_id', true, true, null)->addInteger('created_at', true)
                ->setPrimaryKey(['operation_key'])
                // Retain the operation after deletion, without following a reused SQLite id.
                ->addForeignKey('post_create_operation_post_fk', ['post_id'], ContentSchema::TABLE_NAME, ['id'], 'SET NULL');
        });
    }
}
