<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO as RegisterPdo;

/** Converts an idempotent event batch into sessions, dimensions, and compact report rollups. */
final readonly class AnalyticsIngestor
{
    public const string DIMENSION_GLOBAL = 'global';

    public const string DIMENSION_PAGE = 'page';

    public const string DIMENSION_SOURCE = 'source';

    public const string GLOBAL_KEY = '0000000000000000000000000000000000000000000000000000000000000000';

    private const int ENGAGED_SESSION_SECONDS = 10;

    public function __construct(
        private \PDO               $pdo,
        private DbLayer            $dbLayer,
        private AnalyticsRepository $legacyRepository,
        private AnalyticsReportCache $reportCache,
    ) {
    }

    /**
     * @param list<AnalyticsEvent> $events
     * @return int Number of previously unseen events committed.
     */
    public function ingest(array $events): int
    {
        if ($events === []) {
            return 0;
        }
        $ownsTransaction = !$this->pdo->inTransaction();

        usort($events, static fn(AnalyticsEvent $first, AnalyticsEvent $second): int => [
            $first->occurredAt,
            $first->receivedAt,
            $first->id,
        ] <=> [
            $second->occurredAt,
            $second->receivedAt,
            $second->id,
        ]);

        /** @var array<string, true> $pages */
        $pages = [];
        /** @var array<string, true> $sources */
        $sources = [];
        /** @var array<string, array<string, int|string|bool>|null> $sessions */
        $sessions = [];
        /** @var array<string, array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> $rollups */
        $rollups = [];
        /** @var array<string, int> $legacyHits */
        $legacyHits = [];
        $inserted = 0;

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($events as $event) {
                if (!$this->insertEvent($event)) {
                    continue;
                }
                ++$inserted;

                if (!isset($pages[$event->pageKey])) {
                    $this->touchPage($event);
                    $pages[$event->pageKey] = true;
                }
                if (!isset($sources[$event->sourceKey])) {
                    $this->touchSource($event);
                    $sources[$event->sourceKey] = true;
                }

                if ($event->type === AnalyticsEvent::TYPE_PAGE_VIEW) {
                    $this->applyPageView($event, $sessions, $rollups, $legacyHits);
                } elseif ($event->type === AnalyticsEvent::TYPE_ENGAGEMENT) {
                    $this->applyEngagement($event, $sessions, $rollups);
                }
            }

            foreach ($sessions as $session) {
                if (\is_array($session)) {
                    $this->persistSession($session);
                }
            }
            foreach ($rollups as $rollup) {
                $this->applyRollup($rollup);
            }
            foreach ($legacyHits as $coordinate => $hits) {
                [$day, $visitorKey] = explode("\0", $coordinate, 2);
                $this->legacyRepository->record(
                    $day,
                    AnalyticsRepository::PAGE_CHANNEL,
                    $visitorKey,
                    hitWeight: $hits,
                );
            }

            $this->invalidateReportCache($inserted);
            if ($ownsTransaction) {
                $this->pdo->commit();
                if (!$this->pdo instanceof RegisterPdo && $inserted > 0) {
                    // Native PDO has no after-commit callback support. Runtime connections use
                    // RegisterPdo, but unit embedders still receive the same post-COMMIT guarantee.
                    $this->reportCache->clear();
                }
            }
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        return $inserted;
    }

    private function invalidateReportCache(int $inserted): void
    {
        if ($inserted < 1) {
            return;
        }

        $clear = $this->reportCache->clear(...);
        if ($this->pdo instanceof RegisterPdo && $this->pdo->inTransaction()) {
            $callbackKey = 'analytics-report-cache';
            if ($this->pdo->afterCommitOnce($callbackKey, $clear)) {
                // Remove the old value now and again when the transaction finishes. The
                // completion invalidation closes the race where another worker rebuilds
                // reports from the old committed snapshot before this COMMIT.
                $this->pdo->afterRollbackOnce($callbackKey, $clear);
            }
        }

        $clear();
    }

    public function purge(int $eventBefore, int $sessionBefore, string $uniqueBeforeDay): void
    {
        if ($eventBefore <= 0 || $sessionBefore <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $uniqueBeforeDay) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics retention boundary.');
        }

        $this->dbLayer->delete(AnalyticsSchema::EVENT_TABLE)
            ->where('received_at < :before')->setParameter('before', $eventBefore)
            ->execute();
        $this->dbLayer->delete(AnalyticsSchema::SESSION_TABLE)
            ->where('last_seen_at < :before')->setParameter('before', $sessionBefore)
            ->execute();
        $this->dbLayer->delete(AnalyticsSchema::UNIQUE_DAY_TABLE)
            ->where('day < :day')->setParameter('day', $uniqueBeforeDay)
            ->execute();
    }

    private function insertEvent(AnalyticsEvent $event): bool
    {
        return $this->dbLayer->insert(AnalyticsSchema::EVENT_TABLE)
            ->setValue('event_id', ':event_id')->setParameter('event_id', $event->id)
            ->setValue('event_type', ':event_type')->setParameter('event_type', $event->type)
            ->setValue('occurred_at', ':occurred_at')->setParameter('occurred_at', $event->occurredAt)
            ->setValue('received_at', ':received_at')->setParameter('received_at', $event->receivedAt)
            ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', $event->visitorKey)
            ->setValue('session_key', ':session_key')->setParameter('session_key', $event->sessionKey)
            ->setValue('pageview_id', ':pageview_id')->setParameter('pageview_id', $event->pageViewId)
            ->setValue('page_key', ':page_key')->setParameter('page_key', $event->pageKey)
            ->setValue('source_key', ':source_key')->setParameter('source_key', $event->sourceKey)
            ->setValue('event_name', ':event_name')->setParameter('event_name', $event->name)
            ->setValue('engagement_seconds', ':engagement_seconds')->setParameter('engagement_seconds', $event->engagementSeconds)
            ->setValue('scroll_depth', ':scroll_depth')->setParameter('scroll_depth', $event->scrollDepth)
            ->setValue('properties_json', ':properties_json')->setParameter('properties_json', $event->propertiesJson)
            ->onConflictDoNothing('event_id')
            ->execute()
            ->affectedRows() > 0;
    }

    private function touchPage(AnalyticsEvent $event): void
    {
        $this->dbLayer->insert(AnalyticsSchema::PAGE_TABLE)
            ->setValue('page_key', ':page_key')->setParameter('page_key', $event->pageKey)
            ->setValue('path', ':path')->setParameter('path', $event->path)
            ->setValue('title', ':title')->setParameter('title', $event->title)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $event->occurredAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $event->occurredAt)
            ->onConflictDoNothing('page_key')
            ->execute();

        $this->dbLayer->update(AnalyticsSchema::PAGE_TABLE)
            ->set('path', ':path')->setParameter('path', $event->path)
            ->set('title', ':title')->setParameter('title', $event->title)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $event->occurredAt)
            ->where('page_key = :page_key')->setParameter('page_key', $event->pageKey)
            ->execute();
    }

    private function touchSource(AnalyticsEvent $event): void
    {
        $this->dbLayer->insert(AnalyticsSchema::SOURCE_TABLE)
            ->setValue('source_key', ':source_key')->setParameter('source_key', $event->sourceKey)
            ->setValue('kind', ':kind')->setParameter('kind', $event->sourceKind)
            ->setValue('referrer_host', ':referrer_host')->setParameter('referrer_host', $event->referrerHost)
            ->setValue('utm_source', ':utm_source')->setParameter('utm_source', $event->utmSource)
            ->setValue('utm_medium', ':utm_medium')->setParameter('utm_medium', $event->utmMedium)
            ->setValue('utm_campaign', ':utm_campaign')->setParameter('utm_campaign', $event->utmCampaign)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $event->occurredAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $event->occurredAt)
            ->onConflictDoNothing('source_key')
            ->execute();

        $this->dbLayer->update(AnalyticsSchema::SOURCE_TABLE)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $event->occurredAt)
            ->where('source_key = :source_key')->setParameter('source_key', $event->sourceKey)
            ->execute();
    }

    /**
     * @param array<string, array<string, int|string|bool>|null> $sessions
     * @param array<string, array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> $rollups
     * @param array<string, int> $legacyHits
     */
    private function applyPageView(AnalyticsEvent $event, array &$sessions, array &$rollups, array &$legacyHits): void
    {
        $session = $this->session($event->sessionKey, $sessions);
        if ($session === null) {
            $session = [
                'session_key'         => $event->sessionKey,
                'visitor_key'         => $event->visitorKey,
                'started_at'          => $event->occurredAt,
                'last_seen_at'        => $event->occurredAt,
                'landing_page_key'    => $event->pageKey,
                'last_page_key'       => $event->pageKey,
                'source_key'          => $event->sourceKey,
                'pageviews'           => 0,
                'engaged_seconds'     => 0,
                'max_scroll_depth'    => 0,
                'bounced'             => true,
            ];
            foreach ($this->sessionDimensions($session) as [$dimension, $dimensionKey]) {
                $this->addTimedDelta($rollups, $session['started_at'], $dimension, $dimensionKey, sessions: 1, bounces: 1);
            }
        } elseif ((int)$session['pageviews'] === 1 && (bool)$session['bounced']) {
            $session['bounced'] = false;
            foreach ($this->sessionDimensions($session) as [$dimension, $dimensionKey]) {
                $this->addTimedDelta($rollups, (int)$session['started_at'], $dimension, $dimensionKey, bounces: -1);
            }
        }

        $session['pageviews']        = (int)$session['pageviews'] + 1;
        $session['last_seen_at']     = max((int)$session['last_seen_at'], $event->occurredAt);
        $session['last_page_key']    = $event->pageKey;
        $sessions[$event->sessionKey] = $session;

        foreach ($this->eventDimensions($event) as [$dimension, $dimensionKey]) {
            $this->addTimedDelta($rollups, $event->occurredAt, $dimension, $dimensionKey, views: 1);
            $day = date('Y-m-d', $event->occurredAt);
            if ($this->rememberUnique($day, $dimension, $dimensionKey, $event->visitorKey)) {
                $this->addDelta(
                    $rollups,
                    AnalyticsSchema::DAY_ROLLUP_TABLE,
                    $day,
                    $dimension,
                    $dimensionKey,
                    uniqueCount: 1,
                );
            }
        }

        $legacyCoordinate = date('Y-m-d', $event->occurredAt) . "\0" . $event->visitorKey;
        $legacyHits[$legacyCoordinate] = ($legacyHits[$legacyCoordinate] ?? 0) + 1;
    }

    /**
     * @param array<string, array<string, int|string|bool>|null> $sessions
     * @param array<string, array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> $rollups
     */
    private function applyEngagement(AnalyticsEvent $event, array &$sessions, array &$rollups): void
    {
        $session = $this->session($event->sessionKey, $sessions);
        if ($session === null) {
            return;
        }

        $session['last_seen_at']     = max((int)$session['last_seen_at'], $event->occurredAt);
        $session['engaged_seconds']  = (int)$session['engaged_seconds'] + $event->engagementSeconds;
        $session['max_scroll_depth'] = max((int)$session['max_scroll_depth'], $event->scrollDepth);
        if ((bool)$session['bounced'] && $session['engaged_seconds'] >= self::ENGAGED_SESSION_SECONDS) {
            $session['bounced'] = false;
            foreach ($this->sessionDimensions($session) as [$dimension, $dimensionKey]) {
                $this->addTimedDelta($rollups, (int)$session['started_at'], $dimension, $dimensionKey, bounces: -1);
            }
        }
        $sessions[$event->sessionKey] = $session;

        if ($event->engagementSeconds > 0) {
            foreach ($this->eventDimensions($event) as [$dimension, $dimensionKey]) {
                $this->addTimedDelta(
                    $rollups,
                    $event->occurredAt,
                    $dimension,
                    $dimensionKey,
                    engagedSeconds: $event->engagementSeconds,
                );
            }
        }
    }

    /**
     * @param array<string, array<string, int|string|bool>|null> $sessions
     * @return array<string, int|string|bool>|null
     */
    private function session(string $sessionKey, array &$sessions): ?array
    {
        if (array_key_exists($sessionKey, $sessions)) {
            return $sessions[$sessionKey];
        }

        $row = $this->dbLayer->select(
            'session_key, visitor_key, started_at, last_seen_at, landing_page_key, last_page_key, '
            . 'source_key, pageviews, engaged_seconds, max_scroll_depth, bounced'
        )
            ->from(AnalyticsSchema::SESSION_TABLE)
            ->where('session_key = :session_key')->setParameter('session_key', $sessionKey)
            ->execute()
            ->fetchAssoc();
        if ($row === false) {
            $sessions[$sessionKey] = null;
            return null;
        }

        $session = [
            'session_key'      => (string)$row['session_key'],
            'visitor_key'      => (string)$row['visitor_key'],
            'started_at'       => (int)$row['started_at'],
            'last_seen_at'     => (int)$row['last_seen_at'],
            'landing_page_key' => (string)$row['landing_page_key'],
            'last_page_key'    => (string)$row['last_page_key'],
            'source_key'       => (string)$row['source_key'],
            'pageviews'        => (int)$row['pageviews'],
            'engaged_seconds'  => (int)$row['engaged_seconds'],
            'max_scroll_depth' => (int)$row['max_scroll_depth'],
            'bounced'          => (bool)$row['bounced'],
        ];
        $sessions[$sessionKey] = $session;
        return $session;
    }

    /** @param array<string, int|string|bool> $session */
    private function persistSession(array $session): void
    {
        $this->dbLayer->upsert(AnalyticsSchema::SESSION_TABLE)
            ->setKey('session_key', ':session_key')->setParameter('session_key', $session['session_key'])
            ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', $session['visitor_key'])
            ->setValue('started_at', ':started_at')->setParameter('started_at', $session['started_at'])
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $session['last_seen_at'])
            ->setValue('landing_page_key', ':landing_page_key')->setParameter('landing_page_key', $session['landing_page_key'])
            ->setValue('last_page_key', ':last_page_key')->setParameter('last_page_key', $session['last_page_key'])
            ->setValue('source_key', ':source_key')->setParameter('source_key', $session['source_key'])
            ->setValue('pageviews', ':pageviews')->setParameter('pageviews', $session['pageviews'])
            ->setValue('engaged_seconds', ':engaged_seconds')->setParameter('engaged_seconds', $session['engaged_seconds'])
            ->setValue('max_scroll_depth', ':max_scroll_depth')->setParameter('max_scroll_depth', $session['max_scroll_depth'])
            ->setValue('bounced', ':bounced')->setParameter('bounced', $session['bounced'] === true ? 1 : 0)
            ->execute();
    }

    private function rememberUnique(string $day, string $dimension, string $dimensionKey, string $visitorKey): bool
    {
        return $this->dbLayer->insert(AnalyticsSchema::UNIQUE_DAY_TABLE)
            ->setValue('day', ':day')->setParameter('day', $day)
            ->setValue('dimension', ':dimension')->setParameter('dimension', $dimension)
            ->setValue('dimension_key', ':dimension_key')->setParameter('dimension_key', $dimensionKey)
            ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', $visitorKey)
            ->onConflictDoNothing('day', 'dimension', 'dimension_key', 'visitor_key')
            ->execute()
            ->affectedRows() > 0;
    }

    /**
     * @param array<string, array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> $rollups
     */
    private function addTimedDelta(
        array &$rollups,
        int $timestamp,
        string $dimension,
        string $dimensionKey,
        int $views = 0,
        int $sessions = 0,
        int $uniqueCount = 0,
        int $bounces = 0,
        int $engagedSeconds = 0,
    ): void {
        $this->addDelta(
            $rollups,
            AnalyticsSchema::HOUR_ROLLUP_TABLE,
            date('Y-m-d\TH', $timestamp),
            $dimension,
            $dimensionKey,
            $views,
            $sessions,
            $uniqueCount,
            $bounces,
            $engagedSeconds,
        );
        $this->addDelta(
            $rollups,
            AnalyticsSchema::DAY_ROLLUP_TABLE,
            date('Y-m-d', $timestamp),
            $dimension,
            $dimensionKey,
            $views,
            $sessions,
            $uniqueCount,
            $bounces,
            $engagedSeconds,
        );
    }

    /**
     * @param array<string, array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int}> $rollups
     */
    private function addDelta(
        array &$rollups,
        string $table,
        string $bucket,
        string $dimension,
        string $dimensionKey,
        int $views = 0,
        int $sessions = 0,
        int $uniqueCount = 0,
        int $bounces = 0,
        int $engagedSeconds = 0,
    ): void {
        $key = implode("\0", [$table, $bucket, $dimension, $dimensionKey]);
        $rollups[$key] ??= [
            'table'           => $table,
            'bucket'          => $bucket,
            'dimension'       => $dimension,
            'dimension_key'   => $dimensionKey,
            'views'           => 0,
            'sessions'        => 0,
            'unique_count'    => 0,
            'bounces'         => 0,
            'engaged_seconds' => 0,
        ];
        $rollups[$key]['views'] += $views;
        $rollups[$key]['sessions'] += $sessions;
        $rollups[$key]['unique_count'] += $uniqueCount;
        $rollups[$key]['bounces'] += $bounces;
        $rollups[$key]['engaged_seconds'] += $engagedSeconds;
    }

    /** @param array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int} $rollup */
    private function applyRollup(array $rollup): void
    {
        if ($rollup['bounces'] < 0) {
            $this->decrementBounces($rollup, -$rollup['bounces']);
            $rollup['bounces'] = 0;
        }
        if ($rollup['views'] === 0
            && $rollup['sessions'] === 0
            && $rollup['unique_count'] === 0
            && $rollup['bounces'] === 0
            && $rollup['engaged_seconds'] === 0
        ) {
            return;
        }

        $table = $this->dbLayer->getPrefix() . $rollup['table'];
        $sql = match (true) {
            $this->dbLayer instanceof DbLayerPostgres => <<<SQL
                INSERT INTO $table (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds)
                VALUES (:bucket, :dimension, :dimension_key, :views, :sessions, :unique_count, :bounces, :engaged_seconds)
                ON CONFLICT (bucket, dimension, dimension_key) DO UPDATE SET
                    views = $table.views + EXCLUDED.views,
                    sessions = $table.sessions + EXCLUDED.sessions,
                    unique_count = $table.unique_count + EXCLUDED.unique_count,
                    bounces = $table.bounces + EXCLUDED.bounces,
                    engaged_seconds = $table.engaged_seconds + EXCLUDED.engaged_seconds
                SQL,
            $this->dbLayer instanceof DbLayerSqlite => <<<SQL
                INSERT INTO $table (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds)
                VALUES (:bucket, :dimension, :dimension_key, :views, :sessions, :unique_count, :bounces, :engaged_seconds)
                ON CONFLICT (bucket, dimension, dimension_key) DO UPDATE SET
                    views = views + excluded.views,
                    sessions = sessions + excluded.sessions,
                    unique_count = unique_count + excluded.unique_count,
                    bounces = bounces + excluded.bounces,
                    engaged_seconds = engaged_seconds + excluded.engaged_seconds
                SQL,
            default => <<<SQL
                INSERT INTO $table (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds)
                VALUES (:bucket, :dimension, :dimension_key, :views, :sessions, :unique_count, :bounces, :engaged_seconds)
                ON DUPLICATE KEY UPDATE
                    views = views + VALUES(views),
                    sessions = sessions + VALUES(sessions),
                    unique_count = unique_count + VALUES(unique_count),
                    bounces = bounces + VALUES(bounces),
                    engaged_seconds = engaged_seconds + VALUES(engaged_seconds)
                SQL,
        };
        $this->dbLayer->query($sql, [
            'bucket'          => $rollup['bucket'],
            'dimension'       => $rollup['dimension'],
            'dimension_key'   => $rollup['dimension_key'],
            'views'           => $rollup['views'],
            'sessions'        => $rollup['sessions'],
            'unique_count'    => $rollup['unique_count'],
            'bounces'         => $rollup['bounces'],
            'engaged_seconds' => $rollup['engaged_seconds'],
        ]);
    }

    /** @param array{table: string, bucket: string, dimension: string, dimension_key: string, views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int} $rollup */
    private function decrementBounces(array $rollup, int $amount): void
    {
        $this->dbLayer->update($rollup['table'])
            ->set('bounces', 'CASE WHEN bounces >= :amount THEN bounces - :amount ELSE 0 END')
            ->setParameter('amount', $amount)
            ->where('bucket = :bucket')->setParameter('bucket', $rollup['bucket'])
            ->andWhere('dimension = :dimension')->setParameter('dimension', $rollup['dimension'])
            ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', $rollup['dimension_key'])
            ->execute();
    }

    /** @return list<array{string, string}> */
    private function eventDimensions(AnalyticsEvent $event): array
    {
        return [
            [self::DIMENSION_GLOBAL, self::GLOBAL_KEY],
            [self::DIMENSION_PAGE, $event->pageKey],
            [self::DIMENSION_SOURCE, $event->sourceKey],
        ];
    }

    /**
     * @param  array<string, int|string|bool> $session
     * @return list<array{string, string}>
     */
    private function sessionDimensions(array $session): array
    {
        return [
            [self::DIMENSION_GLOBAL, self::GLOBAL_KEY],
            [self::DIMENSION_PAGE, (string)$session['landing_page_key']],
            [self::DIMENSION_SOURCE, (string)$session['source_key']],
        ];
    }
}
