<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Stable identities for incrementally reconciled objects owned by external services. */
final class ExternalImportMapSchema
{
    public const string TABLE_NAME = 'external_import_map';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('source', 32)
                ->addString('external_scope', 64)
                ->addString('entity_type', 32)
                ->addString('external_id', 128)
                ->addString('target_type', 32)
                ->addInteger('target_id', true)
                ->addString('source_hash', 64)
                ->addLongText('source_data', nullable: false)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['source', 'external_scope', 'entity_type', 'external_id'])
                ->addIndex('target_idx', ['target_type', 'target_id'])
                ->addIndex('source_idx', ['source', 'external_scope', 'entity_type'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
