<?php

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentIdAllocator;
use Register\Content\ContentIdentitySchema;
use Register\Content\ContentViewIncrement;
use Register\Content\ContentViewSpool;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\QueryResult;
use Register\Schema\ContentIdentitySchemaMigration;
use Symfony\Component\Filesystem\Filesystem;

final class ContentIdentitySchemaMigrationTest extends Unit
{
    public function testPostgresSeedsThePrefixedSequenceWithoutRewindingLaterAllocations(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE prefixed_content (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO prefixed_content VALUES (500)');

        $db = new class($pdo, 'prefixed_') extends DbLayerPostgres {
            /** @var list<array<int|string, mixed>> */
            public array $sequenceParameters = [];

            #[\Override]
            public function createTable(string $tableName, callable $tableDefinition): void
            {
                (new DbLayerSqlite($this->pdo, $this->prefix))->createTable($tableName, $tableDefinition);
            }

            #[\Override]
            public function query(string $sql, array $params = [], array $types = []): QueryResult
            {
                if (str_starts_with($sql, 'SELECT setval(')) {
                    $this->sequenceParameters[] = $params;

                    return parent::query('SELECT 1');
                }

                return parent::query($sql, $params, $types);
            }
        };
        ContentIdentitySchema::create($db);
        $allocatedId = ContentIdAllocator::allocate($db);
        self::assertGreaterThan(500, $allocatedId);
        ContentIdentitySchema::create($db);
        self::assertSame([['table_name' => 'prefixed_content_identity', 'id' => 500]], $db->sequenceParameters);
    }

    public function testMigrationRetainsLiveAndPendingIdsWithoutRewindingTheAllocator(): void
    {
        $directory = sys_get_temp_dir() . '/register-content-identity-' . bin2hex(random_bytes(6));
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE prefixed_content (id INTEGER PRIMARY KEY)');
            $pdo->exec('INSERT INTO prefixed_content VALUES (25)');
            $db = new DbLayerSqlite($pdo, 'prefixed_');
            $spool = new ContentViewSpool($directory, minimumSegmentAge: 0, shards: 1);
            $spool->append(new ContentViewIncrement(ContentId::post(80), '2026-10-20'));
            $spool->sealDue(time());
            $spool->append(new ContentViewIncrement(ContentId::post(90), '2026-10-20'));
            $migration = new ContentIdentitySchemaMigration($spool);
            $migration->migrate($db);
            self::assertSame(39, $migration->fromGeneration());
            self::assertSame(40, $migration->toGeneration());
            self::assertSame(25, (int)$db->select('id')->from('content')->execute()->result());
            self::assertSame(90, (int)$db->select('MAX(id)')->from(ContentIdentitySchema::TABLE_NAME)->execute()->result());
            self::assertCount(1, $spool->sealedSegments());

            $first = ContentIdAllocator::allocate($db);
            self::assertGreaterThan(90, $first);
            $pdo->exec('DELETE FROM prefixed_content');
            $migration->migrate($db);
            self::assertGreaterThan($first, ContentIdAllocator::allocate($db));

            // Explicitly imported content also reserves its high watermark.
            $pdo->exec('INSERT INTO prefixed_content VALUES (500)');
            self::assertGreaterThan(500, ContentIdAllocator::allocate($db));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}
