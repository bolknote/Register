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
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Module\Analytics\AnalyticsBlogProjector;
use Register\Module\Analytics\AnalyticsEvent;
use Register\Module\Analytics\AnalyticsIngestor;
use Register\Module\Analytics\AnalyticsPresenceStore;
use Register\Module\Analytics\AnalyticsReportCache;
use Register\Module\Analytics\AnalyticsReportRepository;
use Register\Module\Analytics\AnalyticsRepository;
use Register\Module\Analytics\AnalyticsSchema;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class AnalyticsReportRepositoryTest extends Unit
{
    private string $presenceDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->presenceDirectory = sys_get_temp_dir() . '/register_analytics_report_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->presenceDirectory);
    }

    public function testBuildsACompleteContentDashboardAndRealtimeSnapshot(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $this->createLegacyStorage($dbLayer);
        AnalyticsSchema::createEventStorage($dbLayer);
        $cache = new AnalyticsReportCache(new NullAdapter());
        $presence = new AnalyticsPresenceStore(
            $this->presenceDirectory,
            '0123456789abcdef',
            useApcu: false,
        );
        $ingestor = new AnalyticsIngestor(
            $pdo,
            $dbLayer,
            new AnalyticsRepository($dbLayer),
            $cache,
            new AnalyticsBlogProjector($dbLayer),
        );
        $repository = new AnalyticsReportRepository($dbLayer, $cache, $presence);
        $time = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $properties = json_encode([
            'content_type' => 'post',
            'author'       => 'Alice',
            'section'      => 'PHP',
            'device'       => 'mobile',
            'browser'      => 'Chrome',
            'os'           => 'Android',
        ], JSON_THROW_ON_ERROR);
        $view = $this->event(
            str_repeat('1', 32),
            AnalyticsEvent::TYPE_PAGE_VIEW,
            $time,
            propertiesJson: $properties,
        );
        $engagement = $this->event(
            str_repeat('2', 32),
            AnalyticsEvent::TYPE_ENGAGEMENT,
            $time + 31,
            engagementSeconds: 31,
            scrollDepth: 100,
            propertiesJson: $properties,
        );
        $vitals = $this->event(
            str_repeat('3', 32),
            AnalyticsEvent::TYPE_CUSTOM,
            $time + 32,
            name: AnalyticsBlogProjector::EVENT_WEB_VITALS,
            propertiesJson: '{"lcp_ms":2100,"cls_milli":80,"inp_ms":180}',
        );
        $comment = $this->event(
            str_repeat('4', 32),
            AnalyticsEvent::TYPE_CUSTOM,
            $time + 33,
            name: 'comment.submit',
        );
        self::assertSame(4, $ingestor->ingest([$view, $engagement, $vitals, $comment]));

        $dashboard = $repository->dashboard('2026-08-30', '2026-08-30');
        self::assertSame(1, $dashboard['summary']['views']);
        self::assertSame(1, $dashboard['summary']['sessions']);
        self::assertSame(1, $dashboard['summary']['unique_count']);
        self::assertSame(31.0, $dashboard['summary']['average_engagement']);
        self::assertFalse($dashboard['comparison']['has_data']);

        self::assertSame('/post', $dashboard['pages'][0]['path']);
        self::assertSame('Alice', $dashboard['pages'][0]['author']);
        self::assertSame('PHP', $dashboard['pages'][0]['section']);
        self::assertSame(100.0, $dashboard['pages'][0]['read_75_rate']);
        self::assertSame(100.0, $dashboard['pages'][0]['read_100_rate']);
        self::assertSame('Alice', $dashboard['authors'][0]['label']);
        self::assertSame('PHP', $dashboard['sections'][0]['label']);
        self::assertSame('mobile', $dashboard['technology']['devices'][0]['label']);
        self::assertSame('comment.submit', $dashboard['goals'][0]['name']);
        self::assertSame(100.0, $dashboard['goals'][0]['conversion_rate']);
        self::assertSame([1, 1, 1, 1], array_column($dashboard['funnel'], 'count'));
        self::assertSame(['LCP', 'CLS', 'INP'], array_column($dashboard['vitals'], 'metric'));
        self::assertSame(['insufficient', 'insufficient', 'insufficient'], array_column($dashboard['vitals'], 'grade'));
        self::assertSame([1, 1, 1], array_column($dashboard['vitals'], 'good_samples'));

        $presence->touch(str_repeat('a', 64), str_repeat('c', 64), '/post', 'Post', $time + 40);
        $presence->touch(str_repeat('b', 64), str_repeat('c', 64), '/post', 'Post', $time + 41);

        $realtime = $repository->realtime($time + 42);
        self::assertSame(1, $realtime['active_visitors']);
        self::assertSame(2, $realtime['active_sessions']);
        self::assertSame(1, $realtime['views_30m']);
        self::assertSame(2, $realtime['pages'][0]['sessions']);
    }

    public function testWebVitalsWithholdAGradeUntilTheSampleIsLargeEnough(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $this->createLegacyStorage($dbLayer);
        AnalyticsSchema::createEventStorage($dbLayer);
        $cache = new AnalyticsReportCache(new NullAdapter());
        $presence = new AnalyticsPresenceStore(
            $this->presenceDirectory,
            '0123456789abcdef',
            useApcu: false,
        );
        $ingestor = new AnalyticsIngestor(
            $pdo,
            $dbLayer,
            new AnalyticsRepository($dbLayer),
            $cache,
            new AnalyticsBlogProjector($dbLayer),
        );
        $repository = new AnalyticsReportRepository($dbLayer, $cache, $presence);
        $time = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $events = [];
        for ($index = 0; $index < 20; ++$index) {
            $events[] = $this->event(
                str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                AnalyticsEvent::TYPE_CUSTOM,
                $time + $index,
                name: AnalyticsBlogProjector::EVENT_WEB_VITALS,
                propertiesJson: json_encode([
                    'lcp_ms' => $index % 2 === 0 ? 2400 : 2900,
                ], JSON_THROW_ON_ERROR),
            );
        }

        self::assertSame(2, $ingestor->ingest(array_slice($events, 0, 2)));
        $vital = $repository->webVitals('2026-08-30', '2026-08-30')[0];
        self::assertSame('LCP', $vital['metric']);
        self::assertSame(2650.0, $vital['value']);
        self::assertSame(2, $vital['samples']);
        self::assertSame(1, $vital['good_samples']);
        self::assertSame(50.0, $vital['good_rate']);
        self::assertSame('insufficient', $vital['grade']);

        self::assertSame(18, $ingestor->ingest(array_slice($events, 2)));
        $vital = $repository->webVitals('2026-08-30', '2026-08-30')[0];
        self::assertSame(2650.0, $vital['value']);
        self::assertSame(20, $vital['samples']);
        self::assertSame(10, $vital['good_samples']);
        self::assertSame(50.0, $vital['good_rate']);
        self::assertSame('needs', $vital['grade']);
    }

    private function createLegacyStorage(DbLayerSqlite $dbLayer): void
    {
        $dbLayer->createTable('register_analytics_daily', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('channel', 64)
                ->addInteger('hits', true)
                ->addInteger('unique_count', true)
                ->setPrimaryKey(['day', 'channel']);
        });
        $dbLayer->createTable('register_analytics_visitor', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('day', 10)
                ->addString('channel', 64)
                ->addString('fingerprint', 64)
                ->setPrimaryKey(['day', 'channel', 'fingerprint']);
        });
    }

    private function event(
        string $id,
        string $type,
        int $time,
        string $name = '',
        int $engagementSeconds = 0,
        int $scrollDepth = 0,
        string $propertiesJson = '{}',
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            $id,
            $type,
            $time,
            $time,
            str_repeat('f', 64),
            str_repeat('e', 64),
            str_repeat('d', 32),
            hash('sha256', '/post'),
            '/post',
            'Post',
            str_repeat('9', 64),
            'direct',
            '',
            '',
            '',
            '',
            $name,
            $engagementSeconds,
            $scrollDepth,
            $propertiesJson,
        );
    }
}
