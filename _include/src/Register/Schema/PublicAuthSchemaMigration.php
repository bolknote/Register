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

/** Adds public identities and comment notifications in schema generation 17. */
final readonly class PublicAuthSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 16;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 17;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        PublicAuthSchema::create($dbLayer);
    }
}
