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
use Register\Module\Reactions\ReactionUserSchema;
use Register\Module\VisitorIdentity\VisitorUserSchema;

/** Adds browser-to-user links and user attribution for schema generation 18. */
final readonly class VisitorUserSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 17;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 18;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PublicAuthSchema::ensureMagicLinkModerationRequirement($dbLayer);
        VisitorUserSchema::create($dbLayer);
        ReactionUserSchema::create($dbLayer);
    }
}
