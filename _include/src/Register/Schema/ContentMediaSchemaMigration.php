<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayer;

/** Adds the editor-owned media registry introduced by schema generation 16. */
final readonly class ContentMediaSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 15;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 16;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        ContentMediaSchema::create($dbLayer);
    }
}
