<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Analytics\AnalyticsBlogProjector;
use Register\Module\Analytics\AnalyticsEvent;
use Register\Module\Analytics\AnalyticsIngestor;
use Register\Module\Analytics\AnalyticsSchema;
use Register\Schema\AnalyticsWebVitalsSchemaMigration;

final class AnalyticsWebVitalsSchemaMigrationTest extends Unit
{
    public function testCreatesAndRetrySafelyBackfillsTheRetainedDistribution(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        AnalyticsSchema::createEventStorage($dbLayer);
        $dbLayer->dropTable(AnalyticsSchema::PERFORMANCE_VALUE_TABLE);

        $time  = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $pageA = str_repeat('a', 64);
        $pageB = str_repeat('b', 64);
        $this->insertVital($dbLayer, str_repeat('1', 32), $pageA, $time, '{"lcp_ms":2100,"cls_milli":80}');
        $this->insertVital($dbLayer, str_repeat('2', 32), $pageA, $time + 1, '{"lcp_ms":2100}');
        $this->insertVital($dbLayer, str_repeat('3', 32), $pageB, $time + 2, '{"lcp_ms":3100}');

        $migration = new AnalyticsWebVitalsSchemaMigration();
        self::assertSame(27, $migration->fromGeneration());
        self::assertSame(28, $migration->toGeneration());
        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        self::assertTrue($dbLayer->tableExists(AnalyticsSchema::PERFORMANCE_VALUE_TABLE));
        $global = $dbLayer->select('metric', 'value', 'sample_count')
            ->from(AnalyticsSchema::PERFORMANCE_VALUE_TABLE)
            ->where('page_key = :page_key')->setParameter('page_key', AnalyticsIngestor::GLOBAL_KEY)
            ->orderBy('metric', 'value')
            ->execute()
            ->fetchAssocAll();
        self::assertSame([
            ['metric' => 'cls', 'value' => 80, 'sample_count' => 1],
            ['metric' => 'lcp', 'value' => 2100, 'sample_count' => 2],
            ['metric' => 'lcp', 'value' => 3100, 'sample_count' => 1],
        ], $global);

        $page = $dbLayer->select('metric', 'value', 'sample_count')
            ->from(AnalyticsSchema::PERFORMANCE_VALUE_TABLE)
            ->where('page_key = :page_key')->setParameter('page_key', $pageA)
            ->orderBy('metric', 'value')
            ->execute()
            ->fetchAssocAll();
        self::assertSame([
            ['metric' => 'cls', 'value' => 80, 'sample_count' => 1],
            ['metric' => 'lcp', 'value' => 2100, 'sample_count' => 2],
        ], $page);
    }

    private function insertVital(
        DbLayerSqlite $dbLayer,
        string        $eventId,
        string        $pageKey,
        int           $occurredAt,
        string        $propertiesJson,
    ): void {
        $dbLayer->insert(AnalyticsSchema::EVENT_TABLE)
            ->setValue('event_id', ':event_id')->setParameter('event_id', $eventId)
            ->setValue('event_type', ':event_type')->setParameter('event_type', AnalyticsEvent::TYPE_CUSTOM)
            ->setValue('occurred_at', ':occurred_at')->setParameter('occurred_at', $occurredAt)
            ->setValue('received_at', ':received_at')->setParameter('received_at', $occurredAt)
            ->setValue('visitor_key', ':visitor_key')->setParameter('visitor_key', str_repeat('c', 64))
            ->setValue('session_key', ':session_key')->setParameter('session_key', str_repeat('d', 64))
            ->setValue('pageview_id', ':pageview_id')->setParameter('pageview_id', str_repeat('e', 32))
            ->setValue('page_key', ':page_key')->setParameter('page_key', $pageKey)
            ->setValue('source_key', ':source_key')->setParameter('source_key', str_repeat('f', 64))
            ->setValue('event_name', ':event_name')->setParameter(
                'event_name',
                AnalyticsBlogProjector::EVENT_WEB_VITALS,
            )
            ->setValue('engagement_seconds', ':engagement_seconds')->setParameter('engagement_seconds', 0)
            ->setValue('scroll_depth', ':scroll_depth')->setParameter('scroll_depth', 0)
            ->setValue('properties_json', ':properties_json')->setParameter('properties_json', $propertiesJson)
            ->execute();
    }
}
