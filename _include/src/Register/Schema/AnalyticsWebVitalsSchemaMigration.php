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
use Register\Module\Analytics\AnalyticsWebVitalsDistribution;

/** Adds and backfills the value distribution required for Web Vitals p75 reporting. */
final readonly class AnalyticsWebVitalsSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 27;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 28;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        AnalyticsSchema::createPerformanceValueStorage($dbLayer);
        (new AnalyticsWebVitalsDistribution($dbLayer))->rebuildFromRetainedEvents();
    }
}
