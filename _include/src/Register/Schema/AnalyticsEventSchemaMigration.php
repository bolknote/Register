<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Module\Analytics\AnalyticsSchema;

/** Adds the asynchronous analytics event pipeline to existing installations. */
final readonly class AnalyticsEventSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 24;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 25;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        AnalyticsSchema::createEventStorage($dbLayer);
    }
}
