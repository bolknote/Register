<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentViewSpoolReceiptSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Schema\ContentViewSpoolSchemaMigration;

final class ContentViewSpoolSchemaMigrationTest extends Unit
{
    public function testCreatesExactlyOnceReceiptTable(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $migration = new ContentViewSpoolSchemaMigration();

        self::assertSame(28, $migration->fromGeneration());
        self::assertSame(29, $migration->toGeneration());
        $migration->migrate($dbLayer);

        self::assertTrue($dbLayer->tableExists(ContentViewSpoolReceiptSchema::TABLE_NAME));
        self::assertTrue($dbLayer->indexExists(ContentViewSpoolReceiptSchema::TABLE_NAME, 'created_at_idx'));
    }
}
