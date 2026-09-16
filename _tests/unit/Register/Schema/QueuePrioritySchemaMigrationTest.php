<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentPublicationQueueHandler;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Queue\QueuePublisher;
use Register\Schema\QueuePrioritySchemaMigration;

final class QueuePrioritySchemaMigrationTest extends Unit
{
    public function testAddsPrioritiesAndPromotesPendingPublications(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query(<<<'SQL'
CREATE TABLE queue (
    id TEXT NOT NULL,
    code TEXT NOT NULL,
    payload TEXT NOT NULL,
    generation INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    available_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL,
    last_error TEXT NULL,
    failed_at INTEGER NULL,
    PRIMARY KEY (id, code)
)
SQL);
        $dbLayer->query(
            "INSERT INTO queue (id, code, payload, generation, created_at, updated_at, available_at, attempts) "
            . "VALUES ('ordinary', 'ordinary', '[]', 1, 1, 1, 1, 0), "
            . "('scheduled-content', '" . ContentPublicationQueueHandler::CODE . "', '[]', 1, 1, 1, 1, 0)",
        );

        $migration = new QueuePrioritySchemaMigration();
        self::assertSame(33, $migration->fromGeneration());
        self::assertSame(34, $migration->toGeneration());

        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        self::assertSame(
            [
                ['id' => 'ordinary', 'priority' => QueuePublisher::PRIORITY_NORMAL],
                ['id' => 'scheduled-content', 'priority' => QueuePublisher::PRIORITY_HIGH],
            ],
            $dbLayer
                ->select('id, priority')
                ->from('queue')
                ->orderBy('id')
                ->execute()
                ->fetchAssocAll(),
        );
    }
}
