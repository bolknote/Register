<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;

/** The author's overview needs only daily projections, never event or visitor histories. */
final readonly class AnalyticsOverviewRepository
{
    public function __construct(
        private DbLayer              $dbLayer,
        private AnalyticsReportCache $cache,
    ) {
    }

    /**
     * @phpstan-impure
     * @return array{from: string, to: string, previous_from: string, previous_to: string, views: int, previous_views: int, daily_readers: float, previous_daily_readers: float, posts: list<array{path: string, title: string, views: int}>}
     */
    public function overview(\DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        /** @var array{from: string, to: string, previous_from: string, previous_to: string, views: int, previous_views: int, daily_readers: float, previous_daily_readers: float, posts: list<array{path: string, title: string, views: int}>} */
        return $this->cache->remember('author-overview-v1-' . $today->format('Y-m-d'), 30, function () use ($today): array {
            $fromDay      = $today->modify('-7 days')->format('Y-m-d');
            $toDay        = $today->modify('-1 day')->format('Y-m-d');
            $previousFrom = $today->modify('-14 days')->format('Y-m-d');
            $previousTo   = $today->modify('-8 days')->format('Y-m-d');
            $rows = $this->dbLayer->select('bucket, views, unique_count')
                ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
                ->where('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
                ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', AnalyticsIngestor::GLOBAL_KEY)
                ->andWhere('bucket >= :from_day')->setParameter('from_day', $previousFrom)
                ->andWhere('bucket <= :to_day')->setParameter('to_day', $toDay)
                ->execute()
                ->fetchAssocAll();

            $views = 0;
            $previousViews = 0;
            $readers = 0;
            $previousReaders = 0;
            foreach ($rows as $row) {
                if ((string)$row['bucket'] >= $fromDay) {
                    $views += (int)$row['views'];
                    $readers += (int)$row['unique_count'];
                } else {
                    $previousViews += (int)$row['views'];
                    $previousReaders += (int)$row['unique_count'];
                }
            }

            return [
                'from'                   => $fromDay,
                'to'                     => $toDay,
                'previous_from'          => $previousFrom,
                'previous_to'            => $previousTo,
                'views'                  => $views,
                'previous_views'         => $previousViews,
                'daily_readers'          => round($readers / 7, 1),
                'previous_daily_readers' => round($previousReaders / 7, 1),
                'posts'                  => $views > 0 ? $this->topPosts($fromDay, $toDay) : [],
            ];
        });
    }

    /** @return list<array{path: string, title: string, views: int}> */
    private function topPosts(string $fromDay, string $toDay): array
    {
        $prefix = $this->dbLayer->getPrefix();
        $rows = $this->dbLayer->query(
            'SELECT p.path, p.title, SUM(r.views) AS views '
            . 'FROM ' . $prefix . AnalyticsSchema::DAY_ROLLUP_TABLE . ' r '
            . 'INNER JOIN ' . $prefix . AnalyticsSchema::PAGE_TABLE . ' p ON p.page_key = r.dimension_key '
            . 'INNER JOIN ' . $prefix . AnalyticsSchema::PAGE_METADATA_TABLE . ' m ON m.page_key = p.page_key '
            . 'WHERE r.dimension = :dimension AND r.bucket >= :from_day AND r.bucket <= :to_day '
            . 'AND m.content_type = :content_type '
            . 'GROUP BY p.page_key, p.path, p.title HAVING SUM(r.views) > 0 '
            . 'ORDER BY views DESC, p.path LIMIT 5',
            [
                'dimension'    => AnalyticsIngestor::DIMENSION_PAGE,
                'from_day'     => $fromDay,
                'to_day'       => $toDay,
                'content_type' => 'post',
            ],
        )->fetchAssocAll();

        return array_values(array_map(static fn(array $row): array => [
            'path'  => (string)$row['path'],
            'title' => (string)$row['title'],
            'views' => (int)$row['views'],
        ], $rows));
    }
}
