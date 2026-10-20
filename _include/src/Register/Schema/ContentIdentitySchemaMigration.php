<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentIdentitySchema;
use Register\Content\ContentViewSpool;
use Register\Core\Pdo\DbLayer;

final readonly class ContentIdentitySchemaMigration implements SchemaMigrationInterface
{
    public function __construct(private ContentViewSpool $viewSpool)
    {
    }

    #[\Override]
    public function fromGeneration(): int
    {
        return 39;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 40;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        // A deleted item may still be present in a pre-upgrade view segment.
        // Reserve those ids too, without losing queued views of surviving items.
        ContentIdentitySchema::create($dbLayer, $this->viewSpool->highestContentId());
    }
}
