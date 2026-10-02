<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentMediaIdentitySchema;
use Register\Core\Pdo\DbLayer;

final readonly class ContentMediaIdentitySchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 36;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 37;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        ContentMediaIdentitySchema::create($dbLayer);
    }
}
