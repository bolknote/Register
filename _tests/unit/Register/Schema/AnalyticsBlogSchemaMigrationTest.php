<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Analytics\AnalyticsSchema;
use Register\Schema\AnalyticsBlogSchemaMigration;

final class AnalyticsBlogSchemaMigrationTest extends Unit
{
    public function testCreatesBlogProjectionsAndIsRetrySafe(): void
    {
        $dbLayer   = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $migration = new AnalyticsBlogSchemaMigration();

        self::assertSame(26, $migration->fromGeneration());
        self::assertSame(27, $migration->toGeneration());
        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        foreach ([
            AnalyticsSchema::PAGE_VIEW_TABLE,
            AnalyticsSchema::DIMENSION_TABLE,
            AnalyticsSchema::PAGE_METADATA_TABLE,
            AnalyticsSchema::GOAL_TABLE,
            AnalyticsSchema::GOAL_DAY_TABLE,
            AnalyticsSchema::GOAL_UNIQUE_DAY_TABLE,
            AnalyticsSchema::PERFORMANCE_DAY_TABLE,
        ] as $table) {
            self::assertTrue($dbLayer->tableExists($table), $table);
        }
    }
}
