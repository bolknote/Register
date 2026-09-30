<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\PostCreateOperationSchema;
use Register\Core\Pdo\DbLayer;

final readonly class PostCreateOperationSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 35;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 36;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PostCreateOperationSchema::create($dbLayer);
    }
}
