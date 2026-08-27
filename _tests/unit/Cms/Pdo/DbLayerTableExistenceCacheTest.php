<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use PHPUnit\Framework\TestCase;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Register\Core\Pdo\SchemaBuilderInterface;

final class DbLayerTableExistenceCacheTest extends TestCase
{
    public function testRepeatedLookupUsesRequestLocalResultAndResetQueriesAgain(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE example (id INTEGER PRIMARY KEY)');
        $pdo->clearState();

        $dbLayer = new DbLayerSqlite($pdo);

        self::assertTrue($dbLayer->tableExists('example'));
        self::assertTrue($dbLayer->tableExists('example'));
        self::assertCount(1, $pdo->cleanLogs());

        $dbLayer->clearState();
        self::assertTrue($dbLayer->tableExists('example'));
        self::assertCount(1, $pdo->cleanLogs());

        $dbLayer->dropTable('example');
        self::assertFalse($dbLayer->tableExists('example'));
        self::assertCount(2, $pdo->cleanLogs());
    }

    public function testCreateTableRefreshesPreviouslyNegativeResult(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->clearState();

        $dbLayer = new DbLayerSqlite($pdo);

        self::assertFalse($dbLayer->tableExists('fresh'));
        self::assertCount(1, $pdo->cleanLogs());
        $dbLayer->createTable('fresh', static function (SchemaBuilderInterface $table): void {
            $table->addIdColumn();
        });
        self::assertTrue($dbLayer->tableExists('fresh'));
        self::assertCount(2, $pdo->cleanLogs());
    }
}
