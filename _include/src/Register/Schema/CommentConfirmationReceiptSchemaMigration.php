<?php

declare(strict_types = 1);

namespace Register\Schema;

use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayer;

final readonly class CommentConfirmationReceiptSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 38;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 39;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PublicAuthSchema::ensureCommentConfirmationReceipt($dbLayer);
    }
}
