<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Url\TagUrlAliasSchema;

final readonly class UrlHistorySchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 30;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 31;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        TagUrlAliasSchema::create($dbLayer);
    }
}
