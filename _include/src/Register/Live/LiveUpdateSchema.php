<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Stores a monotonic, payload-free change journal for live browser regions. */
final class LiveUpdateSchema
{
    public const string TABLE_NAME = 'live_updates';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('topic', 16)
                ->addString('content_type', 8)
                ->addInteger('content_id', true)
                ->addInteger('created_at', true)
                ->addIndex('target_cursor_idx', ['topic', 'content_type', 'content_id', 'id'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
