<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayer;

/** Keeps the spam assessment attached while a guest confirms their email. */
final readonly class PendingCommentSpamSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 23;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 24;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PublicAuthSchema::ensurePendingCommentSpamAssessment($dbLayer);
    }
}
