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
use Register\Core\Queue\QueuePublisher;
use Register\Module\Search\Service\ContentIndexer;
use Register\Schema\FuturePublicationSchemaMigration;

final class FuturePublicationSchemaMigrationTest extends Unit
{
    public function testMovesOnlyFuturePublishedRowsIntoTheSchedule(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query(<<<'SQL'
CREATE TABLE content (
    id INTEGER PRIMARY KEY,
    content_type TEXT NOT NULL,
    published INTEGER NOT NULL,
    published_at INTEGER NULL,
    scheduled_at INTEGER NOT NULL DEFAULT 0
)
SQL);
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

        $now = time();
        $dbLayer->query(sprintf(
            'INSERT INTO content (id, content_type, published, published_at, scheduled_at) VALUES '
            . "(1, 'post', 1, %d, 0), (2, 'post', 1, %d, 0), (3, 'post', 0, NULL, %d)",
            $now - 60,
            $now + 3600,
            $now + 7200,
        ));

        $migration = new FuturePublicationSchemaMigration(new QueuePublisher($pdo, ''));
        self::assertSame(29, $migration->fromGeneration());
        self::assertSame(30, $migration->toGeneration());
        $migration->migrate($dbLayer);

        $rows = $dbLayer->select('id, published, published_at, scheduled_at')
            ->from('content')
            ->orderBy('id')
            ->execute()
            ->fetchAssocAll();

        self::assertSame(1, (int)$rows[0]['published']);
        self::assertSame($now - 60, (int)$rows[0]['published_at']);
        self::assertSame(0, (int)$rows[0]['scheduled_at']);
        self::assertSame(0, (int)$rows[1]['published']);
        self::assertNull($rows[1]['published_at']);
        self::assertSame($now + 3600, (int)$rows[1]['scheduled_at']);
        self::assertSame(0, (int)$rows[2]['published']);
        self::assertNull($rows[2]['published_at']);
        self::assertSame($now + 7200, (int)$rows[2]['scheduled_at']);

        $jobs = $dbLayer->select('id, code, payload')
            ->from('queue')
            ->orderBy('id')
            ->execute()
            ->fetchAssocAll();
        self::assertSame([
            ['id' => 'post:2', 'code' => ContentIndexer::QUEUE_CODE, 'payload' => '[]'],
            ['id' => 'post:3', 'code' => ContentIndexer::QUEUE_CODE, 'payload' => '[]'],
        ], $jobs);

        // A retry must keep the cleanup jobs valid even though the content update is now a no-op.
        $migration->migrate($dbLayer);
        self::assertSame(2, (int)$dbLayer->select('COUNT(*)')->from('queue')->execute()->result());
        self::assertSame(2, (int)$dbLayer
            ->select('MIN(generation)')
            ->from('queue')
            ->execute()
            ->result());
    }
}
