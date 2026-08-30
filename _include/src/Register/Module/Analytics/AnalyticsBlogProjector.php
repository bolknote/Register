<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;

/** Maintains bounded projections needed by a content-first blog analytics dashboard. */
final class AnalyticsBlogProjector
{
    public const string DIMENSION_AUTHOR = 'author';

    public const string DIMENSION_SECTION = 'section';

    public const string DIMENSION_CONTENT_TYPE = 'content_type';

    public const string DIMENSION_DEVICE = 'device';

    public const string DIMENSION_BROWSER = 'browser';

    public const string DIMENSION_OS = 'os';

    public const string DIMENSION_SCREEN = 'screen';

    public const string DIMENSION_LANGUAGE = 'language';

    public const string GOAL_ENGAGED_30 = 'content.engaged_30s';

    public const string GOAL_READ_75 = 'content.read_75';

    public const string GOAL_READ_100 = 'content.read_100';

    public const string EVENT_WEB_VITALS = 'web_vitals';

    private const int MAX_DIMENSIONS_PER_KIND = 1024;

    private const int MAX_GOALS = 512;

    private const array PROPERTY_DIMENSIONS = [
        'author'       => self::DIMENSION_AUTHOR,
        'section'      => self::DIMENSION_SECTION,
        'content_type' => self::DIMENSION_CONTENT_TYPE,
        'device'       => self::DIMENSION_DEVICE,
        'browser'      => self::DIMENSION_BROWSER,
        'os'           => self::DIMENSION_OS,
        'screen'       => self::DIMENSION_SCREEN,
        'language'     => self::DIMENSION_LANGUAGE,
    ];

    /** @var array<string, string|null> */
    private array $dimensionCache = [];

    /** @var array<string, int> */
    private array $dimensionCounts = [];

    /** @var array<string, string|null> */
    private array $goalCache = [];

    private ?int $goalCount = null;

    public function __construct(private readonly DbLayer $dbLayer)
    {
    }

    /**
     * @return list<array{string, string}>
     */
    public function dimensions(AnalyticsEvent $event): array
    {
        $properties = $this->properties($event);
        $dimensions = [];
        $keys       = [];
        foreach (self::PROPERTY_DIMENSIONS as $property => $kind) {
            $label = $properties[$property] ?? null;
            if (!\is_string($label) || $label === '') {
                continue;
            }

            $key = $this->dimensionKey($kind, $label, $event->occurredAt);
            if ($key === null) {
                continue;
            }

            $dimensions[] = [$kind, $key];
            $keys[$kind]  = $key;
        }

        if ($event->type === AnalyticsEvent::TYPE_PAGE_VIEW) {
            $this->touchPageMetadata($event, $properties, $keys);
        }

        return $dimensions;
    }

    /** Returns false for a repeated logical page view even when its transport event ID changed. */
    public function recordPageView(AnalyticsEvent $event): bool
    {
        return $this->dbLayer->insert(AnalyticsSchema::PAGE_VIEW_TABLE)
            ->setValue('pageview_id', ':pageview_id')->setParameter('pageview_id', $event->pageViewId)
            ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', $event->visitorKey)
            ->setValue('session_key', ':session_key')->setParameter('session_key', $event->sessionKey)
            ->setValue('page_key', ':page_key')->setParameter('page_key', $event->pageKey)
            ->setValue('started_at', ':started_at')->setParameter('started_at', $event->occurredAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $event->occurredAt)
            ->setValue('engaged_seconds', ':engaged_seconds')->setParameter('engaged_seconds', 0)
            ->setValue('max_scroll_depth', ':max_scroll_depth')->setParameter('max_scroll_depth', 0)
            ->setValue('engaged_30', ':engaged_30')->setParameter('engaged_30', 0)
            ->setValue('read_75', ':read_75')->setParameter('read_75', 0)
            ->setValue('read_100', ':read_100')->setParameter('read_100', 0)
            ->onConflictDoNothing('pageview_id')
            ->execute()
            ->affectedRows() > 0;
    }

    public function recordEngagement(AnalyticsEvent $event): void
    {
        $updated = $this->dbLayer->update(AnalyticsSchema::PAGE_VIEW_TABLE)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $event->occurredAt)
            ->set('engaged_seconds', 'engaged_seconds + :engaged_seconds')
            ->setParameter('engaged_seconds', $event->engagementSeconds)
            ->set(
                'max_scroll_depth',
                'CASE WHEN max_scroll_depth < :scroll_depth THEN :scroll_depth ELSE max_scroll_depth END',
            )
            ->setParameter('scroll_depth', $event->scrollDepth)
            ->where('pageview_id = :pageview_id')->setParameter('pageview_id', $event->pageViewId)
            ->execute()
            ->affectedRows();
        if ($updated < 1) {
            return;
        }

        $state = $this->dbLayer
            ->select('visitor_key, page_key, started_at')
            ->from(AnalyticsSchema::PAGE_VIEW_TABLE)
            ->where('pageview_id = :pageview_id')->setParameter('pageview_id', $event->pageViewId)
            ->execute()
            ->fetchAssoc();
        if ($state === false) {
            return;
        }

        foreach ([
            ['engaged_30', ['engaged_seconds >= 30'], self::GOAL_ENGAGED_30],
            ['read_75', [
                'engaged_seconds >= 30',
                'max_scroll_depth >= 75',
            ], self::GOAL_READ_75],
            ['read_100', [
                'engaged_seconds >= 30',
                'max_scroll_depth >= 95',
            ], self::GOAL_READ_100],
        ] as [$flag, $conditions, $goal]) {
            $update = $this->dbLayer->update(AnalyticsSchema::PAGE_VIEW_TABLE)
                ->set($flag, ':reached')->setParameter('reached', 1)
                ->where('pageview_id = :pageview_id')->setParameter('pageview_id', $event->pageViewId)
                ->andWhere($flag . ' = :not_reached')->setParameter('not_reached', 0);
            foreach ($conditions as $condition) {
                $update->andWhere($condition);
            }

            $crossed = $update->execute()
                ->affectedRows() > 0;
            if ($crossed) {
                $this->recordGoal(
                    $goal,
                    (int)$state['started_at'],
                    (string)$state['page_key'],
                    (string)$state['visitor_key'],
                );
            }
        }
    }

    public function recordCustomEvent(AnalyticsEvent $event): void
    {
        if ($event->name === self::EVENT_WEB_VITALS) {
            $this->recordPerformance($event);
            return;
        }

        if ($event->name !== '') {
            $this->recordGoal($event->name, $event->occurredAt, $event->pageKey, $event->visitorKey);
        }
    }

    public function purge(int $pageViewBefore, string $goalUniqueBeforeDay): void
    {
        $this->dbLayer->delete(AnalyticsSchema::PAGE_VIEW_TABLE)
            ->where('last_seen_at < :before')->setParameter('before', $pageViewBefore)
            ->execute();
        $this->dbLayer->delete(AnalyticsSchema::GOAL_UNIQUE_DAY_TABLE)
            ->where('day < :day')->setParameter('day', $goalUniqueBeforeDay)
            ->execute();
    }

    public static function goalKey(string $name): string
    {
        return hash('sha256', "goal\0" . $name);
    }

    /** @return array<string, bool|float|int|string|null> */
    private function properties(AnalyticsEvent $event): array
    {
        try {
            $properties = json_decode($event->propertiesJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($properties) || array_is_list($properties)) {
            return [];
        }

        $result = [];
        foreach ($properties as $key => $value) {
            if (\is_string($key)
                && ($value === null || \is_bool($value) || \is_float($value) || \is_int($value) || \is_string($value))
            ) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function dimensionKey(string $kind, string $label, int $seenAt): ?string
    {
        $identity = $kind . "\0" . mb_strtolower($label, 'UTF-8');
        if (array_key_exists($identity, $this->dimensionCache)) {
            return $this->dimensionCache[$identity];
        }

        $key      = hash('sha256', $identity);
        $existing = $this->dbLayer->select('dimension_key')
            ->from(AnalyticsSchema::DIMENSION_TABLE)
            ->where('dimension_key = :dimension_key')->setParameter('dimension_key', $key)
            ->execute()
            ->result();
        if (!\is_string($existing)) {
            $count = $this->dimensionCounts[$kind] ??= (int)$this->dbLayer
                ->select('COUNT(*)')
                ->from(AnalyticsSchema::DIMENSION_TABLE)
                ->where('kind = :kind')->setParameter('kind', $kind)
                ->execute()
                ->result();
            if ($count >= self::MAX_DIMENSIONS_PER_KIND) {
                $this->rememberDimension($identity, null);
                return null;
            }

            ++$this->dimensionCounts[$kind];
        }

        $this->dbLayer->insert(AnalyticsSchema::DIMENSION_TABLE)
            ->setValue('dimension_key', ':dimension_key')->setParameter('dimension_key', $key)
            ->setValue('kind', ':kind')->setParameter('kind', $kind)
            ->setValue('label', ':label')->setParameter('label', $label)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $seenAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $seenAt)
            ->onConflictDoNothing('dimension_key')
            ->execute();
        $this->dbLayer->update(AnalyticsSchema::DIMENSION_TABLE)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $seenAt)
            ->where('dimension_key = :dimension_key')->setParameter('dimension_key', $key)
            ->execute();

        $this->rememberDimension($identity, $key);
        return $key;
    }

    private function rememberDimension(string $identity, ?string $key): void
    {
        $this->dimensionCache[$identity] = $key;
        if (\count($this->dimensionCache) > 256) {
            array_shift($this->dimensionCache);
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $properties
     * @param array<string, string>                     $keys
     */
    private function touchPageMetadata(AnalyticsEvent $event, array $properties, array $keys): void
    {
        $contentType = \is_string($properties['content_type'] ?? null)
            ? $properties['content_type']
            : 'other';
        $contentId   = \is_string($properties['content_id'] ?? null)
            ? $properties['content_id']
            : '';
        $publishedAt = \is_int($properties['published_at'] ?? null)
            ? $properties['published_at']
            : 0;
        $wordCount   = \is_int($properties['word_count'] ?? null)
            ? $properties['word_count']
            : 0;

        $this->dbLayer->insert(AnalyticsSchema::PAGE_METADATA_TABLE)
            ->setValue('page_key', ':page_key')->setParameter('page_key', $event->pageKey)
            ->setValue('content_type', ':content_type')->setParameter('content_type', $contentType)
            ->setValue('content_id', ':content_id')->setParameter('content_id', $contentId)
            ->setValue('author_key', ':author_key')->setParameter('author_key', $keys[self::DIMENSION_AUTHOR] ?? '')
            ->setValue('section_key', ':section_key')->setParameter('section_key', $keys[self::DIMENSION_SECTION] ?? '')
            ->setValue('published_at', ':published_at')->setParameter('published_at', $publishedAt)
            ->setValue('word_count', ':word_count')->setParameter('word_count', $wordCount)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $event->occurredAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $event->occurredAt)
            ->onConflictDoNothing('page_key')
            ->execute();
        $this->dbLayer->update(AnalyticsSchema::PAGE_METADATA_TABLE)
            ->set('content_type', ':content_type')->setParameter('content_type', $contentType)
            ->set('content_id', ':content_id')->setParameter('content_id', $contentId)
            ->set('author_key', ':author_key')->setParameter('author_key', $keys[self::DIMENSION_AUTHOR] ?? '')
            ->set('section_key', ':section_key')->setParameter('section_key', $keys[self::DIMENSION_SECTION] ?? '')
            ->set('published_at', ':published_at')->setParameter('published_at', $publishedAt)
            ->set('word_count', ':word_count')->setParameter('word_count', $wordCount)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $event->occurredAt)
            ->where('page_key = :page_key')->setParameter('page_key', $event->pageKey)
            ->execute();
    }

    private function recordGoal(string $name, int $occurredAt, string $pageKey, string $visitorKey): void
    {
        $goalKey = $this->touchGoal($name, $occurredAt);
        if ($goalKey === null) {
            return;
        }

        $day = date('Y-m-d', $occurredAt);
        foreach (array_unique([AnalyticsIngestor::GLOBAL_KEY, $pageKey]) as $coordinate) {
            $unique = $this->dbLayer->insert(AnalyticsSchema::GOAL_UNIQUE_DAY_TABLE)
                ->setValue('day', ':day')->setParameter('day', $day)
                ->setValue('goal_key', ':goal_key')->setParameter('goal_key', $goalKey)
                ->setValue('page_key', ':page_key')->setParameter('page_key', $coordinate)
                ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', $visitorKey)
                ->onConflictDoNothing('day', 'goal_key', 'page_key', 'visitor_key')
                ->execute()
                ->affectedRows() > 0;

            $this->dbLayer->insert(AnalyticsSchema::GOAL_DAY_TABLE)
                ->setValue('bucket', ':bucket')->setParameter('bucket', $day)
                ->setValue('goal_key', ':goal_key')->setParameter('goal_key', $goalKey)
                ->setValue('page_key', ':page_key')->setParameter('page_key', $coordinate)
                ->setValue('events', ':events')->setParameter('events', 0)
                ->setValue('unique_count', ':unique_count')->setParameter('unique_count', 0)
                ->onConflictDoNothing('bucket', 'goal_key', 'page_key')
                ->execute();
            $this->dbLayer->update(AnalyticsSchema::GOAL_DAY_TABLE)
                ->set('events', 'events + :events')->setParameter('events', 1)
                ->set('unique_count', 'unique_count + :unique_count')->setParameter('unique_count', $unique ? 1 : 0)
                ->where('bucket = :bucket')->setParameter('bucket', $day)
                ->andWhere('goal_key = :goal_key')->setParameter('goal_key', $goalKey)
                ->andWhere('page_key = :page_key')->setParameter('page_key', $coordinate)
                ->execute();
        }
    }

    private function touchGoal(string $name, int $seenAt): ?string
    {
        if (array_key_exists($name, $this->goalCache)) {
            return $this->goalCache[$name];
        }

        $key      = self::goalKey($name);
        $existing = $this->dbLayer->select('goal_key')
            ->from(AnalyticsSchema::GOAL_TABLE)
            ->where('goal_key = :goal_key')->setParameter('goal_key', $key)
            ->execute()
            ->result();
        if (!\is_string($existing)) {
            $this->goalCount ??= (int)$this->dbLayer
                ->select('COUNT(*)')
                ->from(AnalyticsSchema::GOAL_TABLE)
                ->execute()
                ->result();
            if ($this->goalCount >= self::MAX_GOALS) {
                $this->rememberGoal($name, null);
                return null;
            }

            ++$this->goalCount;
        }

        $this->dbLayer->insert(AnalyticsSchema::GOAL_TABLE)
            ->setValue('goal_key', ':goal_key')->setParameter('goal_key', $key)
            ->setValue('name', ':name')->setParameter('name', $name)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $seenAt)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $seenAt)
            ->onConflictDoNothing('goal_key')
            ->execute();
        $this->dbLayer->update(AnalyticsSchema::GOAL_TABLE)
            ->set('last_seen_at', 'CASE WHEN last_seen_at < :last_seen_at THEN :last_seen_at ELSE last_seen_at END')
            ->setParameter('last_seen_at', $seenAt)
            ->where('goal_key = :goal_key')->setParameter('goal_key', $key)
            ->execute();

        $this->rememberGoal($name, $key);
        return $key;
    }

    private function rememberGoal(string $name, ?string $key): void
    {
        $this->goalCache[$name] = $key;
        if (\count($this->goalCache) > 128) {
            array_shift($this->goalCache);
        }
    }

    private function recordPerformance(AnalyticsEvent $event): void
    {
        $properties = $this->properties($event);
        $values = [
            'lcp' => $this->boundedMetric($properties['lcp_ms'] ?? null, 120000),
            'cls' => $this->boundedMetric($properties['cls_milli'] ?? null, 10000),
            'inp' => $this->boundedMetric($properties['inp_ms'] ?? null, 60000),
        ];
        if (array_filter($values, static fn(?int $value): bool => $value !== null) === []) {
            return;
        }

        $delta = array_fill_keys([
            'sample_count',
            'lcp_sum', 'lcp_count', 'lcp_good', 'lcp_needs', 'lcp_poor',
            'cls_sum', 'cls_count', 'cls_good', 'cls_needs', 'cls_poor',
            'inp_sum', 'inp_count', 'inp_good', 'inp_needs', 'inp_poor',
        ], 0);
        $delta['sample_count'] = 1;
        foreach ($values as $metric => $value) {
            if ($value === null) {
                continue;
            }

            $delta[$metric . '_sum']   = $value;
            $delta[$metric . '_count'] = 1;
            $grade = match ($metric) {
                'lcp' => $value <= 2500 ? 'good' : ($value <= 4000 ? 'needs' : 'poor'),
                'cls' => $value <= 100 ? 'good' : ($value <= 250 ? 'needs' : 'poor'),
                'inp' => $value <= 200 ? 'good' : ($value <= 500 ? 'needs' : 'poor'),
            };
            $delta[$metric . '_' . $grade] = 1;
        }

        $day = date('Y-m-d', $event->occurredAt);
        foreach (array_unique([AnalyticsIngestor::GLOBAL_KEY, $event->pageKey]) as $pageKey) {
            $insert = $this->dbLayer->insert(AnalyticsSchema::PERFORMANCE_DAY_TABLE)
                ->setValue('bucket', ':bucket')->setParameter('bucket', $day)
                ->setValue('page_key', ':page_key')->setParameter('page_key', $pageKey);
            foreach (array_keys($delta) as $field) {
                $insert->setValue($field, ':' . $field)->setParameter($field, 0);
            }

            $insert->onConflictDoNothing('bucket', 'page_key')->execute();

            $update = $this->dbLayer->update(AnalyticsSchema::PERFORMANCE_DAY_TABLE);
            foreach ($delta as $field => $value) {
                $update->set($field, $field . ' + :' . $field)->setParameter($field, $value);
            }

            $update
                ->where('bucket = :bucket')->setParameter('bucket', $day)
                ->andWhere('page_key = :page_key')->setParameter('page_key', $pageKey)
                ->execute();
        }
    }

    private function boundedMetric(mixed $value, int $maximum): ?int
    {
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        $value = (int)round($value);
        return $value >= 0 && $value <= $maximum ? $value : null;
    }
}
