<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentViewSpoolReceiptSchema;
use Register\Core\Pdo\DbLayer;

/** Adds exactly-once receipts for disk-spooled content views in schema generation 29. */
final readonly class ContentViewSpoolSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 28;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 29;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        ContentViewSpoolReceiptSchema::create($dbLayer);
    }
}
