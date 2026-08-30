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
        private AnalyticsPresenceStore $presenceStore,
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

    /**
     * @return list<array{path: string, title: string, content_type: string, author: string, section: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int, read_75: int, read_100: int, bounce_rate: float, average_engagement: float, read_75_rate: float, read_100_rate: float}>
     */
    public function topPages(string $fromDay, string $toDay, int $limit = 10): array
    {
        $this->validateRange($fromDay, $toDay, $limit);
        /** @var list<array{path: string, title: string, content_type: string, author: string, section: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int, read_75: int, read_100: int, bounce_rate: float, average_engagement: float, read_75_rate: float, read_100_rate: float}> */
        return $this->cache->remember(
            'top-pages-' . $fromDay . '-' . $toDay . '-' . $limit,
            self::RANKING_CACHE_TTL,
            function () use ($fromDay, $toDay, $limit): array {
                $prefix    = $this->dbLayer->getPrefix();
                $rollup    = $prefix . AnalyticsSchema::DAY_ROLLUP_TABLE;
                $page      = $prefix . AnalyticsSchema::PAGE_TABLE;
                $metadata  = $prefix . AnalyticsSchema::PAGE_METADATA_TABLE;
                $dimension = $prefix . AnalyticsSchema::DIMENSION_TABLE;
                $goalDay   = $prefix . AnalyticsSchema::GOAL_DAY_TABLE;
                $rows   = $this->dbLayer->query(
                    'SELECT p.path, p.title, COALESCE(m.content_type, :other_type) AS content_type, '
                    . 'COALESCE(author.label, :empty_author) AS author, '
                    . 'COALESCE(section.label, :empty_section) AS section, '
                    . 'SUM(r.views) AS views, SUM(r.sessions) AS sessions, '
                    . 'SUM(r.unique_count) AS unique_count, SUM(r.bounces) AS bounces, '
                    . 'SUM(r.engaged_seconds) AS engaged_seconds, '
                    . 'COALESCE(SUM(read75.events), 0) AS read_75, '
                    . 'COALESCE(SUM(read100.events), 0) AS read_100 '
                    . 'FROM ' . $rollup . ' r INNER JOIN ' . $page . ' p ON p.page_key = r.dimension_key '
                    . 'LEFT JOIN ' . $metadata . ' m ON m.page_key = p.page_key '
                    . 'LEFT JOIN ' . $dimension . ' author ON author.dimension_key = m.author_key '
                    . 'LEFT JOIN ' . $dimension . ' section ON section.dimension_key = m.section_key '
                    . 'LEFT JOIN ' . $goalDay . ' read75 ON read75.bucket = r.bucket '
                    . 'AND read75.page_key = r.dimension_key AND read75.goal_key = :read_75_key '
                    . 'LEFT JOIN ' . $goalDay . ' read100 ON read100.bucket = r.bucket '
                    . 'AND read100.page_key = r.dimension_key AND read100.goal_key = :read_100_key '
                    . 'WHERE r.dimension = :dimension AND r.bucket >= :from_day AND r.bucket <= :to_day '
                    . 'GROUP BY p.page_key, p.path, p.title, m.content_type, author.label, section.label '
                    . 'ORDER BY views DESC, p.path LIMIT ' . $limit,
                    [
                        'dimension'    => AnalyticsIngestor::DIMENSION_PAGE,
                        'empty_author' => '',
                        'empty_section' => '',
                        'from_day'     => $fromDay,
                        'other_type'   => 'other',
                        'read_75_key'  => AnalyticsBlogProjector::goalKey(AnalyticsBlogProjector::GOAL_READ_75),
                        'read_100_key' => AnalyticsBlogProjector::goalKey(AnalyticsBlogProjector::GOAL_READ_100),
                        'to_day'       => $toDay,
                    ],
                )->fetchAssocAll();

                return array_values(array_map(static function (array $row): array {
                    $views    = (int)$row['views'];
                    $sessions = (int)$row['sessions'];
                    $read75   = (int)$row['read_75'];
                    $read100  = (int)$row['read_100'];
                    return [
                        'path'               => (string)$row['path'],
                        'title'              => (string)$row['title'],
                        'content_type'       => (string)$row['content_type'],
                        'author'             => (string)$row['author'],
                        'section'            => (string)$row['section'],
                        'views'              => $views,
                        'sessions'           => $sessions,
                        'unique_count'       => (int)$row['unique_count'],
                        'bounces'            => (int)$row['bounces'],
                        'engaged_seconds'    => (int)$row['engaged_seconds'],
                        'read_75'            => $read75,
                        'read_100'           => $read100,
                        'bounce_rate'        => $sessions > 0 ? round(100 * (int)$row['bounces'] / $sessions, 1) : 0.0,
                        'average_engagement' => $views > 0 ? round((int)$row['engaged_seconds'] / $views, 1) : 0.0,
                        'read_75_rate'        => $views > 0 ? round(100 * $read75 / $views, 1) : 0.0,
                        'read_100_rate'       => $views > 0 ? round(100 * $read100 / $views, 1) : 0.0,
                    ];
                }, $rows));
            },
        );
    }

    /** @return list<array{kind: string, referrer_host: string, utm_source: string, utm_medium: string, utm_campaign: string, views: int, sessions: int, unique_count: int, bounces: int, bounce_rate: float}> */
    public function topSources(string $fromDay, string $toDay, int $limit = 10): array
    {
        $this->validateRange($fromDay, $toDay, $limit);
        /** @var list<array{kind: string, referrer_host: string, utm_source: string, utm_medium: string, utm_campaign: string, views: int, sessions: int, unique_count: int, bounces: int, bounce_rate: float}> */
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

                return array_values(array_map(static function (array $row): array {
                    $sessions = (int)$row['sessions'];
                    $bounces  = (int)$row['bounces'];
                    return [
                        'kind'          => (string)$row['kind'],
                        'referrer_host' => (string)$row['referrer_host'],
                        'utm_source'    => (string)$row['utm_source'],
                        'utm_medium'    => (string)$row['utm_medium'],
                        'utm_campaign'  => (string)$row['utm_campaign'],
                        'views'         => (int)$row['views'],
                        'sessions'      => $sessions,
                        'unique_count'  => (int)$row['unique_count'],
                        'bounces'       => $bounces,
                        'bounce_rate'   => $sessions > 0 ? round(100 * $bounces / $sessions, 1) : 0.0,
                    ];
                }, $rows));
            },
        );
    }

    /**
     * Complete range payload used by the dashboard; every expensive component is independently cached.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $fromDay, string $toDay): array
    {
        $this->validateRange($fromDay, $toDay, 10);
        return $this->cache->remember(
            'dashboard-v2-' . $fromDay . '-' . $toDay,
            self::LIVE_CACHE_TTL,
            function () use ($fromDay, $toDay): array {
                $summary = $this->rangeSummary($fromDay, $toDay);
                [$previousFrom, $previousTo] = $this->previousRange($fromDay, $toDay);
                $previous = $this->rangeSummary($previousFrom, $previousTo);
                $deltas   = [];
                foreach ([
                    'views',
                    'unique_count',
                    'sessions',
                    'bounce_rate',
                    'average_engagement',
                    'pages_per_session',
                ] as $metric) {
                    $deltas[$metric] = $this->percentageDelta(
                        (float)$summary[$metric],
                        (float)$previous[$metric],
                    );
                }

                return [
                    'range' => [
                        'from' => $fromDay,
                        'to'   => $toDay,
                    ],
                    'earliest_day' => $this->earliestDay() ?? $fromDay,
                    'summary'      => $summary,
                    'comparison'   => [
                        'from'      => $previousFrom,
                        'to'        => $previousTo,
                        'summary'   => $previous,
                        'deltas'    => $deltas,
                        'has_data'  => $previous['views'] > 0 || $previous['sessions'] > 0,
                    ],
                    'daily'        => $this->dailyRange($fromDay, $toDay),
                    'pages'        => $this->topPages($fromDay, $toDay, 20),
                    'sources'      => $this->topSources($fromDay, $toDay, 12),
                    'authors'      => $this->topDimensions(AnalyticsBlogProjector::DIMENSION_AUTHOR, $fromDay, $toDay),
                    'sections'     => $this->topDimensions(AnalyticsBlogProjector::DIMENSION_SECTION, $fromDay, $toDay),
                    'goals'        => $this->topGoals($fromDay, $toDay, 20),
                    'funnel'       => $this->contentFunnel($fromDay, $toDay),
                    'technology'   => [
                        'devices' => $this->topDimensions(AnalyticsBlogProjector::DIMENSION_DEVICE, $fromDay, $toDay),
                        'browsers' => $this->topDimensions(AnalyticsBlogProjector::DIMENSION_BROWSER, $fromDay, $toDay),
                        'systems'  => $this->topDimensions(AnalyticsBlogProjector::DIMENSION_OS, $fromDay, $toDay),
                    ],
                    'vitals'       => $this->webVitals($fromDay, $toDay),
                    'realtime'     => $this->realtime(time()),
                ];
            },
        );
    }

    /**
     * @return array{views: int, sessions: int, unique_count: int, unique_mode: string, bounces: int, engaged_seconds: int, bounce_rate: float, average_engagement: float, pages_per_session: float}
     */
    public function rangeSummary(string $fromDay, string $toDay): array
    {
        $this->validateRange($fromDay, $toDay, 1);
        /** @var array{views: int, sessions: int, unique_count: int, unique_mode: string, bounces: int, engaged_seconds: int, bounce_rate: float, average_engagement: float, pages_per_session: float} */
        return $this->cache->remember(
            'range-summary-' . $fromDay . '-' . $toDay,
            self::LIVE_CACHE_TTL,
            function () use ($fromDay, $toDay): array {
                $row = $this->dbLayer->select(
                    'COALESCE(SUM(views), 0) AS views, COALESCE(SUM(sessions), 0) AS sessions, '
                    . 'COALESCE(SUM(unique_count), 0) AS unique_count, COALESCE(SUM(bounces), 0) AS bounces, '
                    . 'COALESCE(SUM(engaged_seconds), 0) AS engaged_seconds',
                )
                    ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
                    ->where('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
                    ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', AnalyticsIngestor::GLOBAL_KEY)
                    ->andWhere('bucket >= :from_day')->setParameter('from_day', $fromDay)
                    ->andWhere('bucket <= :to_day')->setParameter('to_day', $toDay)
                    ->execute()
                    ->fetchAssoc();
                $row = $row === false ? [] : $row;
                $views           = (int)($row['views'] ?? 0);
                $sessions        = (int)($row['sessions'] ?? 0);
                $uniqueCount     = (int)($row['unique_count'] ?? 0);
                $bounces         = (int)($row['bounces'] ?? 0);
                $engagedSeconds  = (int)($row['engaged_seconds'] ?? 0);
                $uniqueMode      = 'daily';

                if ($this->canUseRetainedIdentity($fromDay)) {
                    [$fromTimestamp, $toTimestamp] = $this->rangeTimestamps($fromDay, $toDay);
                    $uniqueCount = (int)$this->dbLayer->select('COUNT(DISTINCT visitor_key)')
                        ->from(AnalyticsSchema::SESSION_TABLE)
                        ->where('started_at >= :from_timestamp')->setParameter('from_timestamp', $fromTimestamp)
                        ->andWhere('started_at < :to_timestamp')->setParameter('to_timestamp', $toTimestamp)
                        ->execute()
                        ->result();
                    $uniqueMode = 'exact';
                }

                return [
                    'views'              => $views,
                    'sessions'           => $sessions,
                    'unique_count'       => $uniqueCount,
                    'unique_mode'        => $uniqueMode,
                    'bounces'            => $bounces,
                    'engaged_seconds'    => $engagedSeconds,
                    'bounce_rate'        => $sessions > 0 ? round(100 * $bounces / $sessions, 1) : 0.0,
                    'average_engagement' => $views > 0 ? round($engagedSeconds / $views, 1) : 0.0,
                    'pages_per_session'  => $sessions > 0 ? round($views / $sessions, 2) : 0.0,
                ];
            },
        );
    }

    /** @return list<array{day: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> */
    public function dailyRange(string $fromDay, string $toDay): array
    {
        $this->validateRange($fromDay, $toDay, 1);
        return array_values(array_filter(
            $this->dailyOverview(),
            static fn(array $row): bool => $row['day'] >= $fromDay && $row['day'] <= $toDay,
        ));
    }

    /**
     * @return list<array{label: string, views: int, sessions: int, unique_count: int, engaged_seconds: int, average_engagement: float}>
     */
    public function topDimensions(
        string $dimension,
        string $fromDay,
        string $toDay,
        int $limit = 10,
    ): array {
        if (!\in_array($dimension, [
            AnalyticsBlogProjector::DIMENSION_AUTHOR,
            AnalyticsBlogProjector::DIMENSION_SECTION,
            AnalyticsBlogProjector::DIMENSION_CONTENT_TYPE,
            AnalyticsBlogProjector::DIMENSION_DEVICE,
            AnalyticsBlogProjector::DIMENSION_BROWSER,
            AnalyticsBlogProjector::DIMENSION_OS,
            AnalyticsBlogProjector::DIMENSION_SCREEN,
            AnalyticsBlogProjector::DIMENSION_LANGUAGE,
        ], true)) {
            throw new \InvalidArgumentException('Unknown analytics dimension.');
        }
        $this->validateRange($fromDay, $toDay, $limit);

        /** @var list<array{label: string, views: int, sessions: int, unique_count: int, engaged_seconds: int, average_engagement: float}> */
        return $this->cache->remember(
            'top-dimension-' . $dimension . '-' . $fromDay . '-' . $toDay . '-' . $limit,
            self::RANKING_CACHE_TTL,
            function () use ($dimension, $fromDay, $toDay, $limit): array {
                $rollup = $this->dbLayer->getPrefix() . AnalyticsSchema::DAY_ROLLUP_TABLE;
                $labels = $this->dbLayer->getPrefix() . AnalyticsSchema::DIMENSION_TABLE;
                $rows = $this->dbLayer->query(
                    'SELECT d.label, SUM(r.views) AS views, SUM(r.sessions) AS sessions, '
                    . 'SUM(r.unique_count) AS unique_count, SUM(r.engaged_seconds) AS engaged_seconds '
                    . 'FROM ' . $rollup . ' r INNER JOIN ' . $labels . ' d ON d.dimension_key = r.dimension_key '
                    . 'WHERE r.dimension = :dimension AND d.kind = :kind '
                    . 'AND r.bucket >= :from_day AND r.bucket <= :to_day '
                    . 'GROUP BY d.dimension_key, d.label ORDER BY views DESC, d.label LIMIT ' . $limit,
                    [
                        'dimension' => $dimension,
                        'kind'      => $dimension,
                        'from_day'  => $fromDay,
                        'to_day'    => $toDay,
                    ],
                )->fetchAssocAll();

                return array_values(array_map(static function (array $row): array {
                    $views = (int)$row['views'];
                    return [
                        'label'              => (string)$row['label'],
                        'views'              => $views,
                        'sessions'           => (int)$row['sessions'],
                        'unique_count'       => (int)$row['unique_count'],
                        'engaged_seconds'    => (int)$row['engaged_seconds'],
                        'average_engagement' => $views > 0 ? round((int)$row['engaged_seconds'] / $views, 1) : 0.0,
                    ];
                }, $rows));
            },
        );
    }

    /** @return list<array{name: string, events: int, unique_count: int, conversion_rate: float}> */
    public function topGoals(string $fromDay, string $toDay, int $limit = 20): array
    {
        $this->validateRange($fromDay, $toDay, $limit);
        /** @var list<array{name: string, events: int, unique_count: int, conversion_rate: float}> */
        return $this->cache->remember(
            'top-goals-' . $fromDay . '-' . $toDay . '-' . $limit,
            self::RANKING_CACHE_TTL,
            function () use ($fromDay, $toDay, $limit): array {
                $goal    = $this->dbLayer->getPrefix() . AnalyticsSchema::GOAL_TABLE;
                $goalDay = $this->dbLayer->getPrefix() . AnalyticsSchema::GOAL_DAY_TABLE;
                $rows = $this->dbLayer->query(
                    'SELECT g.goal_key, g.name, SUM(d.events) AS events, SUM(d.unique_count) AS unique_count '
                    . 'FROM ' . $goalDay . ' d INNER JOIN ' . $goal . ' g ON g.goal_key = d.goal_key '
                    . 'WHERE d.page_key = :page_key AND d.bucket >= :from_day AND d.bucket <= :to_day '
                    . 'AND g.name NOT LIKE :internal_prefix '
                    . 'GROUP BY g.goal_key, g.name ORDER BY events DESC, g.name LIMIT ' . $limit,
                    [
                        'page_key'        => AnalyticsIngestor::GLOBAL_KEY,
                        'from_day'        => $fromDay,
                        'to_day'          => $toDay,
                        'internal_prefix' => 'content.%',
                    ],
                )->fetchAssocAll();

                $exact = [];
                if ($this->canUseRetainedIdentity($fromDay) && $rows !== []) {
                    $uniqueTable = $this->dbLayer->getPrefix() . AnalyticsSchema::GOAL_UNIQUE_DAY_TABLE;
                    foreach ($this->dbLayer->query(
                        'SELECT goal_key, COUNT(DISTINCT visitor_key) AS unique_count FROM ' . $uniqueTable . ' '
                        . 'WHERE page_key = :page_key AND day >= :from_day AND day <= :to_day GROUP BY goal_key',
                        [
                            'page_key' => AnalyticsIngestor::GLOBAL_KEY,
                            'from_day' => $fromDay,
                            'to_day'   => $toDay,
                        ],
                    )->fetchAssocAll() as $uniqueRow) {
                        $exact[(string)$uniqueRow['goal_key']] = (int)$uniqueRow['unique_count'];
                    }
                }

                $visitors = $this->rangeSummary($fromDay, $toDay)['unique_count'];
                return array_values(array_map(static function (array $row) use ($exact, $visitors): array {
                    $uniqueCount = $exact[(string)$row['goal_key']] ?? (int)$row['unique_count'];
                    return [
                        'name'            => (string)$row['name'],
                        'events'          => (int)$row['events'],
                        'unique_count'    => $uniqueCount,
                        'conversion_rate' => $visitors > 0 ? round(100 * $uniqueCount / $visitors, 1) : 0.0,
                    ];
                }, $rows));
            },
        );
    }

    /**
     * @return list<array{name: string, count: int, rate: float}>
     */
    public function contentFunnel(string $fromDay, string $toDay): array
    {
        $this->validateRange($fromDay, $toDay, 1);
        $totals = $this->contentGoalTotals([
            AnalyticsBlogProjector::GOAL_ENGAGED_30,
            AnalyticsBlogProjector::GOAL_READ_75,
            AnalyticsBlogProjector::GOAL_READ_100,
        ], $fromDay, $toDay);
        $views = (int)$this->dbLayer->select('COALESCE(SUM(views), 0)')
            ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
            ->where('dimension = :dimension')->setParameter('dimension', AnalyticsBlogProjector::DIMENSION_CONTENT_TYPE)
            ->andWhere('dimension_key = :dimension_key')->setParameter(
                'dimension_key',
                hash('sha256', "content_type\0post"),
            )
            ->andWhere('bucket >= :from_day')->setParameter('from_day', $fromDay)
            ->andWhere('bucket <= :to_day')->setParameter('to_day', $toDay)
            ->execute()
            ->result();
        $steps = [
            ['name' => 'view', 'count' => $views],
            ['name' => AnalyticsBlogProjector::GOAL_ENGAGED_30, 'count' => $totals[AnalyticsBlogProjector::GOAL_ENGAGED_30] ?? 0],
            ['name' => AnalyticsBlogProjector::GOAL_READ_75, 'count' => $totals[AnalyticsBlogProjector::GOAL_READ_75] ?? 0],
            ['name' => AnalyticsBlogProjector::GOAL_READ_100, 'count' => $totals[AnalyticsBlogProjector::GOAL_READ_100] ?? 0],
        ];
        return array_map(static fn(array $step): array => [
            'name'  => $step['name'],
            'count' => $step['count'],
            'rate'  => $views > 0 ? round(100 * $step['count'] / $views, 1) : 0.0,
        ], $steps);
    }

    /** @return list<array{metric: string, value: float, unit: string, samples: int, good_rate: float, grade: string}> */
    public function webVitals(string $fromDay, string $toDay): array
    {
        $this->validateRange($fromDay, $toDay, 1);
        /** @var list<array{metric: string, value: float, unit: string, samples: int, good_rate: float, grade: string}> */
        return $this->cache->remember(
            'web-vitals-' . $fromDay . '-' . $toDay,
            self::RANKING_CACHE_TTL,
            function () use ($fromDay, $toDay): array {
                $row = $this->dbLayer->select(
                    'COALESCE(SUM(lcp_sum), 0) AS lcp_sum, COALESCE(SUM(lcp_count), 0) AS lcp_count, '
                    . 'COALESCE(SUM(lcp_good), 0) AS lcp_good, COALESCE(SUM(lcp_needs), 0) AS lcp_needs, '
                    . 'COALESCE(SUM(cls_sum), 0) AS cls_sum, COALESCE(SUM(cls_count), 0) AS cls_count, '
                    . 'COALESCE(SUM(cls_good), 0) AS cls_good, COALESCE(SUM(cls_needs), 0) AS cls_needs, '
                    . 'COALESCE(SUM(inp_sum), 0) AS inp_sum, COALESCE(SUM(inp_count), 0) AS inp_count, '
                    . 'COALESCE(SUM(inp_good), 0) AS inp_good, COALESCE(SUM(inp_needs), 0) AS inp_needs',
                )
                    ->from(AnalyticsSchema::PERFORMANCE_DAY_TABLE)
                    ->where('page_key = :page_key')->setParameter('page_key', AnalyticsIngestor::GLOBAL_KEY)
                    ->andWhere('bucket >= :from_day')->setParameter('from_day', $fromDay)
                    ->andWhere('bucket <= :to_day')->setParameter('to_day', $toDay)
                    ->execute()
                    ->fetchAssoc();
                if ($row === false) {
                    return [];
                }

                $result = [];
                foreach ([
                    'LCP' => ['key' => 'lcp', 'unit' => 'ms', 'divisor' => 1],
                    'CLS' => ['key' => 'cls', 'unit' => '', 'divisor' => 1000],
                    'INP' => ['key' => 'inp', 'unit' => 'ms', 'divisor' => 1],
                ] as $metric => $definition) {
                    $key     = $definition['key'];
                    $count   = (int)$row[$key . '_count'];
                    if ($count < 1) {
                        continue;
                    }
                    $good    = (int)$row[$key . '_good'];
                    $needs   = (int)$row[$key . '_needs'];
                    $grade   = $good / $count >= 0.75
                        ? 'good'
                        : (($good + $needs) / $count >= 0.75 ? 'needs' : 'poor');
                    $value = (int)$row[$key . '_sum'] / $count / $definition['divisor'];
                    $result[] = [
                        'metric'    => $metric,
                        'value'     => $metric === 'CLS' ? round($value, 3) : round($value),
                        'unit'      => $definition['unit'],
                        'samples'   => $count,
                        'good_rate' => round(100 * $good / $count, 1),
                        'grade'     => $grade,
                    ];
                }
                return $result;
            },
        );
    }

    /** @return array{active_visitors: int, active_sessions: int, views_30m: int, updated_at: int, pages: list<array{path: string, title: string, sessions: int}>} */
    public function realtime(int $now): array
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('Invalid realtime analytics timestamp.');
        }
        /** @var array{active_visitors: int, active_sessions: int, views_30m: int, updated_at: int, pages: list<array{path: string, title: string, sessions: int}>} */
        return $this->cache->remember('realtime-' . intdiv($now, 15), self::LIVE_CACHE_TTL, function () use ($now): array {
            $viewsSince  = $now - 30 * 60;
            $views = (int)$this->dbLayer->select('COUNT(*)')
                ->from(AnalyticsSchema::EVENT_TABLE)
                ->where('event_type = :event_type')->setParameter('event_type', AnalyticsEvent::TYPE_PAGE_VIEW)
                ->andWhere('occurred_at >= :views_since')->setParameter('views_since', $viewsSince)
                ->execute()
                ->result();

            $presence = $this->presenceStore->snapshot($now);
            $visitors = [];
            $pageGroups = [];
            $updatedAt = 0;
            foreach ($presence as $entry) {
                $visitors[$entry['visitor_key']] = true;
                $pageKey = hash('sha256', $entry['path']);
                $pageGroups[$pageKey] ??= [
                    'path'     => $entry['path'],
                    'title'    => $entry['title'],
                    'sessions' => 0,
                ];
                $pageGroups[$pageKey]['sessions']++;
                if ($entry['title'] !== '') {
                    $pageGroups[$pageKey]['title'] = $entry['title'];
                }
                $updatedAt = max($updatedAt, $entry['seen_at']);
            }

            if ($presence === []) {
                // Collector engagement remains a portable fallback for pages without live regions.
                $activeSince = $now - 75;
                $sessionRow = $this->dbLayer->select(
                    'COUNT(*) AS active_sessions, COUNT(DISTINCT visitor_key) AS active_visitors',
                )
                    ->from(AnalyticsSchema::SESSION_TABLE)
                    ->where('last_seen_at >= :active_since')->setParameter('active_since', $activeSince)
                    ->execute()
                    ->fetchAssoc();
                $session = $this->dbLayer->getPrefix() . AnalyticsSchema::SESSION_TABLE;
                $page    = $this->dbLayer->getPrefix() . AnalyticsSchema::PAGE_TABLE;
                $pages = $this->dbLayer->query(
                    'SELECT p.path, p.title, COUNT(*) AS sessions FROM ' . $session . ' s '
                    . 'INNER JOIN ' . $page . ' p ON p.page_key = s.last_page_key '
                    . 'WHERE s.last_seen_at >= :active_since '
                    . 'GROUP BY p.page_key, p.path, p.title ORDER BY sessions DESC, p.path LIMIT 5',
                    ['active_since' => $activeSince],
                )->fetchAssocAll();
                $activeVisitors = (int)($sessionRow['active_visitors'] ?? 0);
                $activeSessions = (int)($sessionRow['active_sessions'] ?? 0);
            } else {
                $pages = array_values($pageGroups);
                usort($pages, static function (array $left, array $right): int {
                    $sessions = $right['sessions'] <=> $left['sessions'];
                    return $sessions !== 0 ? $sessions : $left['path'] <=> $right['path'];
                });
                $pages = array_slice($pages, 0, 5);
                $activeVisitors = \count($visitors);
                $activeSessions = \count($presence);
            }

            return [
                'active_visitors' => $activeVisitors,
                'active_sessions' => $activeSessions,
                'views_30m'       => $views,
                'updated_at'      => $updatedAt > 0 ? $updatedAt : $now,
                'pages'           => array_values(array_map(static fn(array $row): array => [
                    'path'     => (string)$row['path'],
                    'title'    => (string)$row['title'],
                    'sessions' => (int)$row['sessions'],
                ], $pages)),
            ];
        });
    }

    public function earliestDay(): ?string
    {
        $day = $this->dbLayer->select('MIN(bucket)')
            ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
            ->where('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
            ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', AnalyticsIngestor::GLOBAL_KEY)
            ->execute()
            ->result();
        return \is_string($day) && $day !== '' ? $day : null;
    }

    /**
     * @param  list<string> $names
     * @return array<string, int>
     */
    private function contentGoalTotals(array $names, string $fromDay, string $toDay): array
    {
        if ($names === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [
            'content_type' => 'post',
            'from_day'     => $fromDay,
            'to_day'       => $toDay,
        ];
        foreach (array_map(AnalyticsBlogProjector::goalKey(...), $names) as $index => $key) {
            $placeholder = 'goal_' . $index;
            $placeholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $key;
        }

        $goal     = $this->dbLayer->getPrefix() . AnalyticsSchema::GOAL_TABLE;
        $goalDay  = $this->dbLayer->getPrefix() . AnalyticsSchema::GOAL_DAY_TABLE;
        $metadata = $this->dbLayer->getPrefix() . AnalyticsSchema::PAGE_METADATA_TABLE;
        $rows = $this->dbLayer->query(
            'SELECT g.name, SUM(d.events) AS events FROM ' . $goalDay . ' d '
            . 'INNER JOIN ' . $goal . ' g ON g.goal_key = d.goal_key '
            . 'INNER JOIN ' . $metadata . ' m ON m.page_key = d.page_key '
            . 'WHERE m.content_type = :content_type AND d.bucket >= :from_day AND d.bucket <= :to_day '
            . 'AND d.goal_key IN (' . implode(', ', $placeholders) . ') GROUP BY g.goal_key, g.name',
            $parameters,
        )->fetchAssocAll();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string)$row['name']] = (int)$row['events'];
        }
        return $totals;
    }

    /** @return array{string, string} */
    private function previousRange(string $fromDay, string $toDay): array
    {
        $from = new \DateTimeImmutable($fromDay . ' 00:00:00');
        $to   = new \DateTimeImmutable($toDay . ' 00:00:00');
        $days = $from->diff($to)->days + 1;
        $previousTo   = $from->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');
        return [$previousFrom->format('Y-m-d'), $previousTo->format('Y-m-d')];
    }

    private function percentageDelta(float $current, float $previous): ?float
    {
        if ($previous === 0.0) {
            return $current === 0.0 ? 0.0 : null;
        }
        return round(100 * ($current - $previous) / abs($previous), 1);
    }

    /** @return array{int, int} */
    private function rangeTimestamps(string $fromDay, string $toDay): array
    {
        $from = new \DateTimeImmutable($fromDay . ' 00:00:00');
        $to   = new \DateTimeImmutable($toDay . ' 00:00:00');
        return [$from->getTimestamp(), $to->modify('+1 day')->getTimestamp()];
    }

    private function canUseRetainedIdentity(string $fromDay): bool
    {
        $from = new \DateTimeImmutable($fromDay . ' 00:00:00');
        return $from->getTimestamp() >= time() - 89 * 86400;
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
