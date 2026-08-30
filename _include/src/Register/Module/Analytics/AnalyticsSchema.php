<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Portable storage for the event pipeline and its bounded reporting projections. */
final class AnalyticsSchema
{
    public const string EVENT_TABLE = 'register_analytics_event';

    public const string SESSION_TABLE = 'register_analytics_session';

    public const string PAGE_TABLE = 'register_analytics_page';

    public const string SOURCE_TABLE = 'register_analytics_source';

    public const string DAY_ROLLUP_TABLE = 'register_analytics_rollup_day';

    public const string HOUR_ROLLUP_TABLE = 'register_analytics_rollup_hour';

    public const string UNIQUE_DAY_TABLE = 'register_analytics_unique_day';

    public const string PAGE_VIEW_TABLE = 'register_analytics_pageview';

    public const string DIMENSION_TABLE = 'register_analytics_dimension';

    public const string PAGE_METADATA_TABLE = 'register_analytics_page_metadata';

    public const string GOAL_TABLE = 'register_analytics_goal';

    public const string GOAL_DAY_TABLE = 'register_analytics_goal_day';

    public const string GOAL_UNIQUE_DAY_TABLE = 'register_analytics_goal_unique_day';

    public const string PERFORMANCE_DAY_TABLE = 'register_analytics_performance_day';

    public const string PERFORMANCE_VALUE_TABLE = 'register_analytics_performance_value';

    public static function createEventStorage(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::PAGE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('page_key', 64)
                ->addString('path', 1024)
                ->addString('title', 255)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['page_key'])
                ->addIndex('last_seen_idx', ['last_seen_at'])
            ;
        });

        $dbLayer->createTable(self::SOURCE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('source_key', 64)
                ->addString('kind', 16)
                ->addString('referrer_host', 255)
                ->addString('utm_source', 100)
                ->addString('utm_medium', 100)
                ->addString('utm_campaign', 150)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['source_key'])
                ->addIndex('kind_seen_idx', ['kind', 'last_seen_at'])
            ;
        });

        $dbLayer->createTable(self::EVENT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('event_id', 32)
                ->addString('event_type', 24)
                ->addInteger('occurred_at', true)
                ->addInteger('received_at', true)
                ->addString('visitor_key', 64)
                ->addString('session_key', 64)
                ->addString('pageview_id', 32)
                ->addString('page_key', 64)
                ->addString('source_key', 64)
                ->addString('event_name', 64)
                ->addInteger('engagement_seconds', true)
                ->addInteger('scroll_depth', true)
                ->addText('properties_json')
                ->setPrimaryKey(['event_id'])
                ->addIndex('occurred_idx', ['occurred_at'])
                ->addIndex('session_occurred_idx', ['session_key', 'occurred_at'])
                ->addIndex('type_occurred_idx', ['event_type', 'occurred_at'])
            ;
        });

        $dbLayer->createTable(self::SESSION_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('session_key', 64)
                ->addString('visitor_key', 64)
                ->addInteger('started_at', true)
                ->addInteger('last_seen_at', true)
                ->addString('landing_page_key', 64)
                ->addString('last_page_key', 64)
                ->addString('source_key', 64)
                ->addInteger('pageviews', true)
                ->addInteger('engaged_seconds', true)
                ->addInteger('max_scroll_depth', true)
                ->addBoolean('bounced', default: true)
                ->setPrimaryKey(['session_key'])
                ->addIndex('started_idx', ['started_at'])
                ->addIndex('visitor_seen_idx', ['visitor_key', 'last_seen_at'])
                ->addIndex('source_started_idx', ['source_key', 'started_at'])
            ;
        });

        self::createRollupTable($dbLayer, self::DAY_ROLLUP_TABLE, 10);
        self::createRollupTable($dbLayer, self::HOUR_ROLLUP_TABLE, 13);

        $dbLayer->createTable(self::UNIQUE_DAY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('dimension', 16)
                ->addString('dimension_key', 64)
                ->addString('visitor_key', 64)
                ->setPrimaryKey(['day', 'dimension', 'dimension_key', 'visitor_key'])
                ->addIndex('day_idx', ['day'])
            ;
        });

        self::createBlogStorage($dbLayer);
    }

    /** Adds bounded projections used by content, goal, technology, and performance reports. */
    public static function createBlogStorage(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::PAGE_VIEW_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('pageview_id', 32)
                ->addString('visitor_key', 64)
                ->addString('session_key', 64)
                ->addString('page_key', 64)
                ->addInteger('started_at', true)
                ->addInteger('last_seen_at', true)
                ->addInteger('engaged_seconds', true)
                ->addInteger('max_scroll_depth', true)
                ->addBoolean('engaged_30')
                ->addBoolean('read_75')
                ->addBoolean('read_100')
                ->setPrimaryKey(['pageview_id'])
                ->addIndex('last_seen_idx', ['last_seen_at'])
                ->addIndex('page_started_idx', ['page_key', 'started_at'])
                ->addIndex('session_started_idx', ['session_key', 'started_at'])
            ;
        });

        $dbLayer->createTable(self::DIMENSION_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('dimension_key', 64)
                ->addString('kind', 16)
                ->addString('label', 255)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['dimension_key'])
                ->addIndex('kind_seen_idx', ['kind', 'last_seen_at'])
            ;
        });

        $dbLayer->createTable(self::PAGE_METADATA_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('page_key', 64)
                ->addString('content_type', 24)
                ->addString('content_id', 100)
                ->addString('author_key', 64)
                ->addString('section_key', 64)
                ->addInteger('published_at', true)
                ->addInteger('word_count', true)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['page_key'])
                ->addIndex('content_type_seen_idx', ['content_type', 'last_seen_at'])
                ->addIndex('author_seen_idx', ['author_key', 'last_seen_at'])
                ->addIndex('section_seen_idx', ['section_key', 'last_seen_at'])
            ;
        });

        $dbLayer->createTable(self::GOAL_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('goal_key', 64)
                ->addString('name', 64)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['goal_key'])
                ->addUniqueIndex('name_idx', ['name'])
                ->addIndex('last_seen_idx', ['last_seen_at'])
            ;
        });

        $dbLayer->createTable(self::GOAL_DAY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('bucket', 10)
                ->addString('goal_key', 64)
                ->addString('page_key', 64)
                ->addInteger('events', true)
                ->addInteger('unique_count', true)
                ->setPrimaryKey(['bucket', 'goal_key', 'page_key'])
                ->addIndex('goal_bucket_idx', ['goal_key', 'bucket'])
                ->addIndex('page_bucket_idx', ['page_key', 'bucket'])
            ;
        });

        $dbLayer->createTable(self::GOAL_UNIQUE_DAY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('goal_key', 64)
                ->addString('page_key', 64)
                ->addString('visitor_key', 64)
                ->setPrimaryKey(['day', 'goal_key', 'page_key', 'visitor_key'])
                ->addIndex('day_idx', ['day'])
                ->addIndex('goal_day_idx', ['goal_key', 'day'])
            ;
        });

        $dbLayer->createTable(self::PERFORMANCE_DAY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('bucket', 10)
                ->addString('page_key', 64)
                ->addInteger('sample_count', true)
                ->addInteger('lcp_sum', true)
                ->addInteger('lcp_count', true)
                ->addInteger('lcp_good', true)
                ->addInteger('lcp_needs', true)
                ->addInteger('lcp_poor', true)
                ->addInteger('cls_sum', true)
                ->addInteger('cls_count', true)
                ->addInteger('cls_good', true)
                ->addInteger('cls_needs', true)
                ->addInteger('cls_poor', true)
                ->addInteger('inp_sum', true)
                ->addInteger('inp_count', true)
                ->addInteger('inp_good', true)
                ->addInteger('inp_needs', true)
                ->addInteger('inp_poor', true)
                ->setPrimaryKey(['bucket', 'page_key'])
                ->addIndex('page_bucket_idx', ['page_key', 'bucket'])
            ;
        });

        self::createPerformanceValueStorage($dbLayer);
    }

    /** Adds the exact-value histogram used to calculate Web Vitals percentiles. */
    public static function createPerformanceValueStorage(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::PERFORMANCE_VALUE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('bucket', 10)
                ->addString('page_key', 64)
                ->addString('metric', 3)
                ->addInteger('value', true)
                ->addInteger('sample_count', true)
                ->setPrimaryKey(['bucket', 'page_key', 'metric', 'value'])
                ->addIndex('page_metric_bucket_idx', ['page_key', 'metric', 'bucket'])
            ;
        });
    }

    private static function createRollupTable(DbLayer $dbLayer, string $name, int $bucketLength): void
    {
        $dbLayer->createTable($name, static function (SchemaBuilderInterface $table) use ($bucketLength): void {
            $table
                ->addString('bucket', $bucketLength)
                ->addString('dimension', 16)
                ->addString('dimension_key', 64)
                ->addInteger('views', true)
                ->addInteger('sessions', true)
                ->addInteger('unique_count', true)
                ->addInteger('bounces', true)
                ->addInteger('engaged_seconds', true)
                ->setPrimaryKey(['bucket', 'dimension', 'dimension_key'])
                ->addIndex('dimension_bucket_idx', ['dimension', 'bucket'])
            ;
        });
    }
}
