<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;

/** Reads bounded reports from projections instead of scanning the retained event log. */
final readonly class AnalyticsReportRepository
{
    private const int LIVE_CACHE_TTL = 15;

    private const int RANKING_CACHE_TTL = 30;

    public function __construct(
        private DbLayer              $dbLayer,
        private AnalyticsReportCache $cache,
    ) {
    }

    /** @return list<array{day: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> */
    public function dailyOverview(): array
    {
        /** @var list<array{day: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> */
        return $this->cache->remember('daily-overview', self::LIVE_CACHE_TTL, function (): array {
            $rows = $this->dbLayer->select('bucket, views, sessions, unique_count, bounces, engaged_seconds')
                ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
                ->where('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
                ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', AnalyticsIngestor::GLOBAL_KEY)
                ->orderBy('bucket')
                ->execute()
                ->fetchAssocAll();

            return array_values(array_map(static fn(array $row): array => [
                'day'             => (string)$row['bucket'],
                'views'           => (int)$row['views'],
                'sessions'        => (int)$row['sessions'],
                'unique_count'    => (int)$row['unique_count'],
                'bounces'         => (int)$row['bounces'],
                'engaged_seconds' => (int)$row['engaged_seconds'],
            ], $rows));
        });
    }

    /** @return array{views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int, bounce_rate: float} */
    public function summary(string $day): array
    {
        $this->validateDay($day);
        foreach ($this->dailyOverview() as $row) {
            if ($row['day'] === $day) {
                return $this->summaryFromRow([
                    'views'           => $row['views'],
                    'sessions'        => $row['sessions'],
                    'unique_count'    => $row['unique_count'],
                    'bounces'         => $row['bounces'],
                    'engaged_seconds' => $row['engaged_seconds'],
                ]);
            }
        }

        return $this->summaryFromRow([
            'views'           => 0,
            'sessions'        => 0,
            'unique_count'    => 0,
            'bounces'         => 0,
            'engaged_seconds' => 0,
        ]);
    }

    /** @return list<array{path: string, title: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> */
    public function topPages(string $fromDay, string $toDay, int $limit = 10): array
    {
        $this->validateRange($fromDay, $toDay, $limit);
        /** @var list<array{path: string, title: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> */
        return $this->cache->remember(
            'top-pages-' . $fromDay . '-' . $toDay . '-' . $limit,
            self::RANKING_CACHE_TTL,
            function () use ($fromDay, $toDay, $limit): array {
                $rollup = $this->dbLayer->getPrefix() . AnalyticsSchema::DAY_ROLLUP_TABLE;
                $page   = $this->dbLayer->getPrefix() . AnalyticsSchema::PAGE_TABLE;
                $rows   = $this->dbLayer->query(
                    'SELECT p.path, p.title, SUM(r.views) AS views, SUM(r.sessions) AS sessions, '
                    . 'SUM(r.unique_count) AS unique_count, SUM(r.bounces) AS bounces, '
                    . 'SUM(r.engaged_seconds) AS engaged_seconds '
                    . 'FROM ' . $rollup . ' r INNER JOIN ' . $page . ' p ON p.page_key = r.dimension_key '
                    . 'WHERE r.dimension = :dimension AND r.bucket >= :from_day AND r.bucket <= :to_day '
                    . 'GROUP BY p.page_key, p.path, p.title ORDER BY views DESC, p.path LIMIT ' . $limit,
                    [
                        'dimension' => AnalyticsIngestor::DIMENSION_PAGE,
                        'from_day'  => $fromDay,
                        'to_day'    => $toDay,
                    ],
                )->fetchAssocAll();

                return array_values(array_map(static fn(array $row): array => [
                    'path'            => (string)$row['path'],
                    'title'           => (string)$row['title'],
                    'views'           => (int)$row['views'],
                    'sessions'        => (int)$row['sessions'],
                    'unique_count'    => (int)$row['unique_count'],
                    'bounces'         => (int)$row['bounces'],
                    'engaged_seconds' => (int)$row['engaged_seconds'],
                ], $rows));
            },
        );
    }

    /** @return list<array{kind: string, referrer_host: string, utm_source: string, utm_medium: string, utm_campaign: string, views: int, sessions: int, unique_count: int, bounces: int}> */
    public function topSources(string $fromDay, string $toDay, int $limit = 10): array
    {
        $this->validateRange($fromDay, $toDay, $limit);
        /** @var list<array{kind: string, referrer_host: string, utm_source: string, utm_medium: string, utm_campaign: string, views: int, sessions: int, unique_count: int, bounces: int}> */
        return $this->cache->remember(
            'top-sources-' . $fromDay . '-' . $toDay . '-' . $limit,
            self::RANKING_CACHE_TTL,
            function () use ($fromDay, $toDay, $limit): array {
                $rollup = $this->dbLayer->getPrefix() . AnalyticsSchema::DAY_ROLLUP_TABLE;
                $source = $this->dbLayer->getPrefix() . AnalyticsSchema::SOURCE_TABLE;
                $rows   = $this->dbLayer->query(
                    'SELECT s.kind, s.referrer_host, s.utm_source, s.utm_medium, s.utm_campaign, '
                    . 'SUM(r.views) AS views, SUM(r.sessions) AS sessions, SUM(r.unique_count) AS unique_count, '
                    . 'SUM(r.bounces) AS bounces '
                    . 'FROM ' . $rollup . ' r INNER JOIN ' . $source . ' s ON s.source_key = r.dimension_key '
                    . 'WHERE r.dimension = :dimension AND r.bucket >= :from_day AND r.bucket <= :to_day '
                    . 'GROUP BY s.source_key, s.kind, s.referrer_host, s.utm_source, s.utm_medium, s.utm_campaign '
                    . 'ORDER BY views DESC, s.kind LIMIT ' . $limit,
                    [
                        'dimension' => AnalyticsIngestor::DIMENSION_SOURCE,
                        'from_day'  => $fromDay,
                        'to_day'    => $toDay,
                    ],
                )->fetchAssocAll();

                return array_values(array_map(static fn(array $row): array => [
                    'kind'          => (string)$row['kind'],
                    'referrer_host' => (string)$row['referrer_host'],
                    'utm_source'    => (string)$row['utm_source'],
                    'utm_medium'    => (string)$row['utm_medium'],
                    'utm_campaign'  => (string)$row['utm_campaign'],
                    'views'         => (int)$row['views'],
                    'sessions'      => (int)$row['sessions'],
                    'unique_count'  => (int)$row['unique_count'],
                    'bounces'       => (int)$row['bounces'],
                ], $rows));
            },
        );
    }

    /**
     * @param  array{views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int} $row
     * @return array{views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int, bounce_rate: float}
     */
    private function summaryFromRow(array $row): array
    {
        $sessions = $row['sessions'];
        return $row + [
            'bounce_rate' => $sessions > 0 ? round(100 * $row['bounces'] / $sessions, 1) : 0.0,
        ];
    }

    private function validateRange(string $fromDay, string $toDay, int $limit): void
    {
        $this->validateDay($fromDay);
        $this->validateDay($toDay);
        if ($fromDay > $toDay || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid analytics report range.');
        }
    }

    private function validateDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            throw new \InvalidArgumentException('Analytics day must use the YYYY-MM-DD format.');
        }
    }
}
