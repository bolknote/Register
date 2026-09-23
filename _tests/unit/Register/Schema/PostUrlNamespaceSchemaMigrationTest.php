<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Schema\PostUrlNamespaceSchemaMigration;

final class PostUrlNamespaceSchemaMigrationTest extends Unit
{
    public function testMovesOnlyRootPostsAndRetainsTheirPreviousPaths(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query(<<<'SQL'
CREATE TABLE content (
    id INTEGER PRIMARY KEY,
    content_type TEXT NOT NULL,
    slug_scope TEXT NOT NULL,
    slug TEXT NOT NULL,
    UNIQUE (slug_scope, slug)
)
SQL);
        $dbLayer->query(<<<'SQL'
CREATE TABLE content_url_alias (
    path TEXT PRIMARY KEY,
    content_id INTEGER NOT NULL
)
SQL);
        $dbLayer->query(<<<'SQL'
INSERT INTO content (id, content_type, slug_scope, slug) VALUES
    (1, 'post', 'root', 'new-post'),
    (2, 'post', 'root', 'all/historical-post'),
    (3, 'page', 'root', 'page')
SQL);
        $dbLayer->query(<<<'SQL'
INSERT INTO content_url_alias (path, content_id) VALUES
    ('new-post', 1),
    ('all/new-post', 1)
SQL);

        $notifications = [];
        $migration = new PostUrlNamespaceSchemaMigration(
            $pdo,
            static function (array $contentIds) use (&$notifications): void {
                $notifications[] = array_map(
                    static fn(ContentId $contentId): string => (string)$contentId,
                    $contentIds,
                );
            },
        );
        self::assertSame(34, $migration->fromGeneration());
        self::assertSame(35, $migration->toGeneration());

        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        self::assertSame(
            [
                ['id' => 1, 'slug' => 'all/new-post'],
                ['id' => 2, 'slug' => 'all/historical-post'],
                ['id' => 3, 'slug' => 'page'],
            ],
            $dbLayer->select('id, slug')->from('content')->orderBy('id')->execute()->fetchAssocAll(),
        );
        self::assertSame(
            [['path' => 'new-post', 'content_id' => 1]],
            $dbLayer
                ->select('path, content_id')
                ->from('content_url_alias')
                ->orderBy('path')
                ->execute()
                ->fetchAssocAll(),
        );
        self::assertSame([['post:1']], $notifications);
    }

    public function testRefusesToReplaceAnotherCanonicalUrl(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query(<<<'SQL'
CREATE TABLE content (
    id INTEGER PRIMARY KEY,
    content_type TEXT NOT NULL,
    slug_scope TEXT NOT NULL,
    slug TEXT NOT NULL,
    UNIQUE (slug_scope, slug)
)
SQL);
        $dbLayer->query('CREATE TABLE content_url_alias (path TEXT PRIMARY KEY, content_id INTEGER NOT NULL)');
        $dbLayer->query(<<<'SQL'
INSERT INTO content (id, content_type, slug_scope, slug) VALUES
    (1, 'post', 'root', 'collision'),
    (2, 'post', 'root', 'all/collision')
SQL);

        $migration = new PostUrlNamespaceSchemaMigration(
            $pdo,
            static function (array $_contentIds): void {
            },
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already canonical');
        $migration->migrate($dbLayer);
    }
}
