<?php

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Blog\Inplace\PostCreateOperations;
use Register\Schema\PostCreateOperationSchemaMigration;

final class PostCreateOperationSchemaMigrationTest extends Unit
{
    public function testMigrationRetainsReceiptsAndSeparatesAuthors(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO content (id) VALUES (25), (40)');

        $db = new DbLayerSqlite($pdo);
        $migration = new PostCreateOperationSchemaMigration();
        $migration->migrate($db);

        $operations = new PostCreateOperations($db);
        $operations->remember(1, 'same-request-id', 'first-hash', 25);
        $operations->remember(2, 'same-request-id', 'second-hash', 40);

        $migration->migrate($db);
        self::assertSame(['post_id' => 25, 'request_hash' => 'first-hash'], $operations->find(1, 'same-request-id'));
        self::assertSame(['post_id' => 40, 'request_hash' => 'second-hash'], $operations->find(2, 'same-request-id'));
        self::assertNull($operations->find(3, 'same-request-id'));
        self::assertSame(35, $migration->fromGeneration());
        self::assertSame(36, $migration->toGeneration());

        $pdo->exec('DELETE FROM content WHERE id = 25');
        $pdo->exec('INSERT INTO content (id) VALUES (25)');
        self::assertSame(['post_id' => 0, 'request_hash' => 'first-hash'], $operations->find(1, 'same-request-id'));
    }
}
