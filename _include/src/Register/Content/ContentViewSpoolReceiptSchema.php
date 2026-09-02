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

/** Transactional receipts make disk-spooled counter batches exactly-once. */
final class ContentViewSpoolReceiptSchema
{
    public const string TABLE_NAME = 'content_view_spool_receipts';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('receipt_id', 24)
                ->addInteger('created_at', true)
                ->setPrimaryKey(['receipt_id'])
                ->addIndex('created_at_idx', ['created_at'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
