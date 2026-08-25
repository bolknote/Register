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
use Register\Core\Queue\QueueSchema;
use Register\Schema\QueueLeaseSchemaMigration;

final class QueueLeaseSchemaMigrationTest extends Unit
{
    public function testRestoresMissingRunnerLeaseAndIsRetrySafe(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        QueueSchema::createRunnerLeaseStorage($dbLayer);
        $pdo->exec('DELETE FROM ' . QueueSchema::LEASE_TABLE);

        $migration = new QueueLeaseSchemaMigration();
        self::assertSame(21, $migration->fromGeneration());
        self::assertSame(22, $migration->toGeneration());

        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        $statement = $pdo->query(
            'SELECT name, owner, expires_at FROM ' . QueueSchema::LEASE_TABLE,
        );
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([
            'name' => QueueSchema::RUNNER_LEASE,
            'owner' => '',
            'expires_at' => 0,
        ], $row);
    }
}
