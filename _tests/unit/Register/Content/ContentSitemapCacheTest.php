<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use PHPUnit\Framework\TestCase;
use Register\Content\ContentSitemapCache;
use Register\Core\Pdo\PDO;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContentSitemapCacheTest extends TestCase
{
    public function testEveryChangeRefreshesDocumentsAndCountsWithinTheSameTransaction(): void
    {
        foreach ([true, false] as $commit) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY, slug TEXT)');
            $pdo->exec("INSERT INTO pages (slug) VALUES ('original')");
            $cache = new ContentSitemapCache(new ArrayAdapter(), pdo: $pdo);
            $document = static function () use ($pdo): string {
                $statement = $pdo->query('SELECT slug FROM pages ORDER BY id');
                self::assertNotFalse($statement);
                $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);

                return '<urlset>' . implode('', array_map(
                    static function (mixed $slug): string {
                        self::assertIsString($slug);

                        return '<url><loc>https://example.test/' . $slug . '</loc></url>';
                    },
                    $rows,
                )) . '</urlset>';
            };
            $count = static function () use ($pdo): int {
                $statement = $pdo->query('SELECT COUNT(*) FROM pages');
                self::assertNotFalse($statement);

                return (int)$statement->fetchColumn();
            };
            $original = $document();
            self::assertSame($original, $cache->rememberString('part-1', $document));
            self::assertSame(1, $cache->rememberInt('count', $count));

            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO pages (slug) VALUES ('first-change')");
            $cache->invalidate();
            self::assertSame($document(), $cache->rememberString('part-1', $document));
            self::assertSame(2, $cache->rememberInt('count', $count));

            // A render between writes must not freeze the first intermediate
            // sitemap for every subsequent change in a batched operation.
            $pdo->exec("INSERT INTO pages (slug) VALUES ('second-change')");
            $cache->invalidate();
            self::assertSame($document(), $cache->rememberString('part-1', $document));
            self::assertSame(3, $cache->rememberInt('count', $count));

            if ($commit) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }

            self::assertSame($document(), $cache->rememberString('part-1', $document));
            self::assertSame($commit ? 3 : 1, $cache->rememberInt('count', $count));
        }
    }
}
