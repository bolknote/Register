<?php

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Retains allocated ids after their files are removed, including on SQLite. */
final class ContentMediaIdentitySchema
{
    public const string TABLE_NAME = 'content_media_identity';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table->addIdColumn()->addInteger('created_at', true);
        });

        // Existing uploads keep their ids. Seed the allocator above their high
        // watermark without recreating either files or post-media relations.
        $lastMediaId = (int)$dbLayer->select('MAX(id)')->from(ContentMediaSchema::FILE_TABLE)->execute()->result();
        $lastIdentityId = (int)$dbLayer->select('MAX(id)')->from(self::TABLE_NAME)->execute()->result();
        if ($lastMediaId > $lastIdentityId) {
            $dbLayer->insert(self::TABLE_NAME)->values(['id' => ':id', 'created_at' => ':created_at'])
                ->execute(['id' => $lastMediaId, 'created_at' => 0]);
            if ($dbLayer instanceof DbLayerPostgres) {
                // Explicit SERIAL values do not advance PostgreSQL's sequence.
                // Only synchronize after seeding; repeat migrations must not
                // rewind a sequence already advanced by later allocations.
                $dbLayer->query("SELECT setval(pg_get_serial_sequence(:table_name, 'id'), :id, true)", [
                    'table_name' => $dbLayer->getPrefix() . self::TABLE_NAME,
                    'id' => $lastMediaId,
                ]);
            }
        }
    }
}
