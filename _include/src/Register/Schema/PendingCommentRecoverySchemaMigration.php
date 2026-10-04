<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayer;

final readonly class PendingCommentRecoverySchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 37;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 38;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PublicAuthSchema::ensurePendingCommentRecovery($dbLayer);
    }
}
