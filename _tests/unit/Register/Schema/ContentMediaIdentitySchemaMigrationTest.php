<?php

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentMediaIdentitySchema;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\QueryResult;
use Register\Module\Blog\Inplace\PostMediaConflictException;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Register\Schema\ContentMediaIdentitySchemaMigration;

final class ContentMediaIdentitySchemaMigrationTest extends Unit
{
    public function testPostgresSeedAdvancesThePrefixedSequenceOnlyOnce(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE prefixed_content_media_file (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO prefixed_content_media_file VALUES (1250)');
        // Exercise PostgreSQL-specific sequence SQL without a running server;
        // normal reads and writes still execute against disposable SQLite.
        $db = new class($pdo, 'prefixed_') extends DbLayerPostgres {
            /** @var list<array{sql: string, params: array<int|string, mixed>}> */
            public array $sequenceQueries = [];

            #[\Override]
            public function createTable(string $tableName, callable $tableDefinition): void
            {
                (new DbLayerSqlite($this->pdo, $this->prefix))->createTable($tableName, $tableDefinition);
            }

            #[\Override]
            public function query(string $sql, array $params = [], array $types = []): QueryResult
            {
                if (str_starts_with($sql, 'SELECT setval(')) {
                    $this->sequenceQueries[] = ['sql' => $sql, 'params' => $params];

                    return parent::query('SELECT 1');
                }

                return parent::query($sql, $params, $types);
            }
        };
        $migration = new ContentMediaIdentitySchemaMigration();
        $migration->migrate($db);
        self::assertSame([[
            'sql' => "SELECT setval(pg_get_serial_sequence(:table_name, 'id'), :id, true)",
            'params' => ['table_name' => 'prefixed_content_media_identity', 'id' => 1250],
        ]], $db->sequenceQueries);
        $db->insert(ContentMediaIdentitySchema::TABLE_NAME)->values(['created_at' => '1'])->execute();
        self::assertGreaterThan(1250, (int)$db->insertId());
        $migration->migrate($db);
        self::assertCount(1, $db->sequenceQueries);
    }

    public function testUpgradePreservesFilesAndRelationsWithoutReusingDeletedIds(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content VALUES (25)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        $existing = $repository->register($this->media('/existing.png'));
        $pdo->exec('UPDATE content_media_file SET id = 1250 WHERE id = ' . $existing);
        $repository->syncPost(25, '<img src="/media/existing.png" data-post-media-id="1250">', [], 1);
        $db->dropTable(ContentMediaIdentitySchema::TABLE_NAME);

        $migration = new ContentMediaIdentitySchemaMigration();
        $migration->migrate($db);
        self::assertSame(36, $migration->fromGeneration());
        self::assertSame(37, $migration->toGeneration());
        $existingMedia = $repository->find(1250);
        self::assertNotNull($existingMedia);
        self::assertSame('/existing.png', $existingMedia['storage_path']);
        self::assertSame(1, (int)$existingMedia['usage_count']);
        self::assertFalse($repository->hasPersistentIdentity(1250));

        $deleted = $repository->register($this->media('/deleted.png'));
        self::assertGreaterThan(1250, $deleted);
        self::assertTrue($repository->deleteUnused($deleted));
        $migration->migrate($db);
        $replacement = $repository->register($this->media('/replacement.png'));
        self::assertGreaterThan($deleted, $replacement);
        self::assertTrue($repository->hasPersistentIdentity($replacement));
        self::assertNull($repository->find($deleted));

        $repository->releasePost(25);
        self::assertTrue($repository->deleteUnused(1250));
        self::assertTrue($repository->deleteUnused($replacement));
        $migration->migrate($db);
        self::assertGreaterThan($replacement, $repository->register($this->media('/after-empty-table.png')));
        ContentMediaSchema::drop($db);
        self::assertFalse($db->tableExists(ContentMediaIdentitySchema::TABLE_NAME));
    }

    public function testUnavailableMediaIsRejectedInsteadOfSkippingItsRelation(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE content_media_file (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE content_media_usage (post_id INTEGER, media_id INTEGER)');

        $repository = new PostMediaRepository(new DbLayerSqlite($pdo), '/media');
        $this->expectException(PostMediaConflictException::class);
        $this->expectExceptionMessage(PostMediaConflictException::UNAVAILABLE);
        $repository->syncPost(25, '<img src="/media/deleted.png" data-post-media-id="1">', [], 1);
    }

    public function testLegacyDanglingIdCannotAdoptFirstPostUpgradeUpload(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content VALUES (25)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        // The old upload was deleted before upgrading an empty SQLite table.
        // No historic watermark remains, but the new file has a new identity.
        $newId = $repository->register($this->media('/old-canonical-name.png'));
        self::assertSame(1, $newId);
        $this->expectException(PostMediaConflictException::class);
        $this->expectExceptionMessage(PostMediaConflictException::UNAVAILABLE);
        $repository->syncPost(25, '<img src="/media/old-canonical-name.png" data-post-media-id="1">', [], 1, [1]);
    }

    /** @return array{original_name: string, normalized_name: string, storage_path: string, mime_type: string, kind: string, byte_size: int, width: int, height: int, uploaded_by: int} */
    private function media(string $path): array
    {
        return ['original_name' => basename($path), 'normalized_name' => basename($path),
            'storage_path' => $path, 'mime_type' => 'image/png', 'kind' => 'image',
            'byte_size' => 10, 'width' => 1, 'height' => 1, 'uploaded_by' => 1];
    }
}
