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
use Register\Schema\AnalyticsEventSchemaMigration;

final class AnalyticsEventSchemaMigrationTest extends Unit
{
    public function testCreatesEventStorageAndIsRetrySafe(): void
    {
        $dbLayer  = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $migration = new AnalyticsEventSchemaMigration();

        self::assertSame(24, $migration->fromGeneration());
        self::assertSame(25, $migration->toGeneration());
        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        foreach ([
            AnalyticsSchema::EVENT_TABLE,
            AnalyticsSchema::SESSION_TABLE,
            AnalyticsSchema::PAGE_TABLE,
            AnalyticsSchema::SOURCE_TABLE,
            AnalyticsSchema::DAY_ROLLUP_TABLE,
            AnalyticsSchema::HOUR_ROLLUP_TABLE,
            AnalyticsSchema::UNIQUE_DAY_TABLE,
        ] as $table) {
            self::assertTrue($dbLayer->tableExists($table));
        }
    }
}
