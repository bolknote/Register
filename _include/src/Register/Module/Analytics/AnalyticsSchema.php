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
