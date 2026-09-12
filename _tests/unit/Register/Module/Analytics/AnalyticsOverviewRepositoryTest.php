<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Analytics\AnalyticsIngestor;
use Register\Module\Analytics\AnalyticsOverviewRepository;
use Register\Module\Analytics\AnalyticsReportCache;
use Register\Module\Analytics\AnalyticsSchema;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AnalyticsOverviewRepositoryTest extends Unit
{
    public function testUsesCompleteWeeksAndRanksOnlyPostsWithoutReadingIndividualVisits(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        AnalyticsSchema::createEventStorage($dbLayer);
        // The overview must remain independent from expensive retained visit histories.
        $pdo->exec('DROP TABLE ' . AnalyticsSchema::EVENT_TABLE);
        $pdo->exec('DROP TABLE ' . AnalyticsSchema::SESSION_TABLE);
        $pdo->exec('DROP TABLE ' . AnalyticsSchema::PAGE_VIEW_TABLE);
        $pdo->exec('DROP TABLE ' . AnalyticsSchema::UNIQUE_DAY_TABLE);

        foreach ([
            '2026-08-28' => [9000, 900],
            '2026-08-29' => [100, 35],
            '2026-09-04' => [40, 14],
            '2026-09-05' => [200, 70],
            '2026-09-11' => [80, 28],
            '2026-09-12' => [8000, 800],
        ] as $day => [$views, $readers]) {
            $this->rollup($pdo, $day, AnalyticsIngestor::DIMENSION_GLOBAL, AnalyticsIngestor::GLOBAL_KEY, $views, $readers);
        }

        foreach (range(1, 6) as $index) {
            $key = 'post-' . $index;
            $this->page($pdo, $key, '/post-' . $index, 'Post ' . $index, 'post');
            $this->rollup($pdo, '2026-09-06', AnalyticsIngestor::DIMENSION_PAGE, $key, $index * 10, 1);
        }

        // Both range boundaries and multiple daily rows contribute to the winner.
        $this->rollup($pdo, '2026-09-05', AnalyticsIngestor::DIMENSION_PAGE, 'post-1', 100, 1);
        $this->rollup($pdo, '2026-09-11', AnalyticsIngestor::DIMENSION_PAGE, 'post-1', 100, 1);
        $this->rollup($pdo, '2026-09-12', AnalyticsIngestor::DIMENSION_PAGE, 'post-2', 9999, 1);
        $this->rollup($pdo, '2026-09-04', AnalyticsIngestor::DIMENSION_PAGE, 'post-2', 9999, 1);
        $this->page($pdo, 'home', '/', 'Home', 'home');
        $this->rollup($pdo, '2026-09-06', AnalyticsIngestor::DIMENSION_PAGE, 'home', 9999, 1);

        $repository = new AnalyticsOverviewRepository($dbLayer, new AnalyticsReportCache(new ArrayAdapter()));
        $overview = $repository->overview(new \DateTimeImmutable('2026-09-12T23:59:59+00:00'));

        self::assertSame('2026-09-05', $overview['from']);
        self::assertSame('2026-09-11', $overview['to']);
        self::assertSame('2026-08-29', $overview['previous_from']);
        self::assertSame('2026-09-04', $overview['previous_to']);
        self::assertSame(280, $overview['views']);
        self::assertSame(140, $overview['previous_views']);
        self::assertSame(14.0, $overview['daily_readers']);
        self::assertSame(7.0, $overview['previous_daily_readers']);
        self::assertSame(['/post-1', '/post-6', '/post-5', '/post-4', '/post-3'], array_column($overview['posts'], 'path'));
        self::assertSame(['path' => '/post-1', 'title' => 'Post 1', 'views' => 210], $overview['posts'][0]);
    }

    public function testEmptySummaryIsCachedAndRefreshesWithTheSharedAnalyticsInvalidation(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        AnalyticsSchema::createEventStorage($dbLayer);
        $cache = new AnalyticsReportCache(new ArrayAdapter());
        $repository = new AnalyticsOverviewRepository($dbLayer, $cache);
        $today = new \DateTimeImmutable('2026-09-12');

        $empty = $repository->overview($today);
        self::assertSame(0, $empty['views']);
        self::assertSame(0.0, $empty['daily_readers']);
        self::assertSame([], $empty['posts']);

        $this->rollup($pdo, '2026-09-11', AnalyticsIngestor::DIMENSION_GLOBAL, AnalyticsIngestor::GLOBAL_KEY, 7, 7);
        self::assertSame($empty, $repository->overview($today));
        $cache->clear();
        self::assertSame(7, $repository->overview($today)['views']);
        self::assertSame(1.0, $repository->overview($today)['daily_readers']);
        self::assertSame(0, $repository->overview($today->modify('+8 days'))['views']);
    }

    private function rollup(\PDO $pdo, string $day, string $dimension, string $key, int $views, int $readers): void
    {
        $statement = $pdo->prepare('INSERT INTO ' . AnalyticsSchema::DAY_ROLLUP_TABLE
            . ' (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds) '
            . 'VALUES (?, ?, ?, ?, 0, ?, 0, 0)');
        self::assertNotFalse($statement);
        $statement->execute([$day, $dimension, $key, $views, $readers]);
    }

    private function page(\PDO $pdo, string $key, string $path, string $title, string $type): void
    {
        $statement = $pdo->prepare('INSERT INTO ' . AnalyticsSchema::PAGE_TABLE
            . ' (page_key, path, title, first_seen_at, last_seen_at) VALUES (?, ?, ?, 0, 0)');
        self::assertNotFalse($statement);
        $statement->execute([$key, $path, $title]);
        $statement = $pdo->prepare('INSERT INTO ' . AnalyticsSchema::PAGE_METADATA_TABLE
            . ' (page_key, content_type, content_id, author_key, section_key, published_at, word_count, first_seen_at, last_seen_at) '
            . "VALUES (?, ?, '', '', '', 0, 0, 0, 0)");
        self::assertNotFalse($statement);
        $statement->execute([$key, $type]);
    }
}
