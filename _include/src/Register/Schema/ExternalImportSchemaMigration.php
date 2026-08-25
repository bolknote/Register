<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Import\ExternalImportMapSchema;

/** Adds stable external-import identities in schema generation 21. */
final readonly class ExternalImportSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 20;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 21;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        ExternalImportMapSchema::create($dbLayer);
    }
}
