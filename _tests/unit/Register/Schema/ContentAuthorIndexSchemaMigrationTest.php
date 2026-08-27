<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Schema\ContentAuthorIndexSchemaMigration;

final class ContentAuthorIndexSchemaMigrationTest extends Unit
{
    public function testAddsCoveringIndexAndIsRetrySafe(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $dbLayer->query('CREATE TABLE content (content_type TEXT, published INTEGER, author_id INTEGER)');

        $migration = new ContentAuthorIndexSchemaMigration();
        self::assertSame(22, $migration->fromGeneration());
        self::assertSame(23, $migration->toGeneration());

        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        self::assertTrue($dbLayer->indexExists(ContentSchema::TABLE_NAME, 'type_publication_author_idx'));
    }
}
