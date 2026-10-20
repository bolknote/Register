<?php

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Keeps allocated content identifiers even after their content is deleted. */
final class ContentIdentitySchema
{
    public const string TABLE_NAME = 'content_identity';

    public static function create(DbLayer $dbLayer, int $minimumId = 0): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table->addIdColumn()->addInteger('created_at', true);
        });
        self::reserveThrough($dbLayer, $minimumId);
    }

    /** Explicitly imported identifiers must also advance the persistent allocator. */
    public static function reserveThrough(DbLayer $dbLayer, int $minimumId = 0): void
    {
        $lastContentId = max($minimumId, (int)$dbLayer->select('MAX(id)')->from(ContentSchema::TABLE_NAME)->execute()->result());
        $lastIdentityId = (int)$dbLayer->select('MAX(id)')->from(self::TABLE_NAME)->execute()->result();
        if ($lastContentId <= $lastIdentityId) {
            return;
        }

        $dbLayer->insert(self::TABLE_NAME)->values(['id' => ':id', 'created_at' => '0'])
            ->onConflictDoNothing('id')->execute(['id' => $lastContentId]);
        if ($dbLayer instanceof DbLayerPostgres) {
            $dbLayer->query("SELECT setval(pg_get_serial_sequence(:table_name, 'id'), :id, true)", [
                'table_name' => $dbLayer->getPrefix() . self::TABLE_NAME,
                'id' => $lastContentId,
            ]);
        }
    }
}
