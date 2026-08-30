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
use Register\Module\Analytics\AnalyticsEvent;
use Register\Module\Analytics\AnalyticsBlogProjector;
use Register\Module\Analytics\AnalyticsIngestor;
use Register\Module\Analytics\AnalyticsRepository;
use Register\Module\Analytics\AnalyticsReportCache;
use Register\Module\Analytics\AnalyticsSchema;
use Symfony\Component\Cache\Adapter\NullAdapter;

final class AnalyticsIngestorTest extends Unit
{
    public function testBuildsIdempotentSessionsAndRollupsInOneBatch(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $this->createLegacyStorage($dbLayer);
        AnalyticsSchema::createEventStorage($dbLayer);
        $ingestor = new AnalyticsIngestor(
            $pdo,
            $dbLayer,
            new AnalyticsRepository($dbLayer),
            new AnalyticsReportCache(new NullAdapter()),
            new AnalyticsBlogProjector($dbLayer),
        );
        $time     = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $first    = $this->pageView(str_repeat('1', 32), str_repeat('a', 64), str_repeat('b', 64), '/first', $time);
        $second   = $this->pageView(str_repeat('2', 32), str_repeat('a', 64), str_repeat('c', 64), '/second', $time + 60);

        self::assertSame(2, $ingestor->ingest([$second, $first]));
        self::assertSame(0, $ingestor->ingest([$first, $second]));

        $global = $dbLayer->select('views, sessions, unique_count, bounces, engaged_seconds')
            ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
            ->where('bucket = :bucket')->setParameter('bucket', '2026-08-30')
            ->andWhere('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
            ->andWhere('dimension_key = :dimension_key')->setParameter('dimension_key', AnalyticsIngestor::GLOBAL_KEY)
            ->execute()
            ->fetchAssoc();
        self::assertSame([
            'views'           => 2,
            'sessions'        => 1,
            'unique_count'    => 1,
            'bounces'         => 0,
            'engaged_seconds' => 0,
        ], $global);

        $session = $dbLayer->select('pageviews, bounced, landing_page_key, last_page_key')
            ->from(AnalyticsSchema::SESSION_TABLE)
            ->execute()
            ->fetchAssoc();
        self::assertNotFalse($session);
        self::assertSame(2, (int)$session['pageviews']);
        self::assertSame(0, (int)$session['bounced']);
        self::assertSame($first->pageKey, $session['landing_page_key']);
        self::assertSame($second->pageKey, $session['last_page_key']);

        $legacy = $dbLayer->select('hits, unique_count')
            ->from('register_analytics_daily')
            ->execute()
            ->fetchAssoc();
        self::assertSame(['hits' => 2, 'unique_count' => 1], $legacy);
        self::assertSame(2, (int)$dbLayer->select('COUNT(*)')->from(AnalyticsSchema::EVENT_TABLE)->execute()->result());
    }

    public function testEngagementTurnsASinglePageSessionIntoANonBounce(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $this->createLegacyStorage($dbLayer);
        AnalyticsSchema::createEventStorage($dbLayer);
        $ingestor = new AnalyticsIngestor(
            $pdo,
            $dbLayer,
            new AnalyticsRepository($dbLayer),
            new AnalyticsReportCache(new NullAdapter()),
            new AnalyticsBlogProjector($dbLayer),
        );
        $time     = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $view     = $this->pageView(str_repeat('3', 32), str_repeat('d', 64), str_repeat('e', 64), '/engaged', $time);
        $engaged  = new AnalyticsEvent(
            str_repeat('4', 32),
            AnalyticsEvent::TYPE_ENGAGEMENT,
            $time + 15,
            $time + 15,
            $view->visitorKey,
            $view->sessionKey,
            $view->pageViewId,
            $view->pageKey,
            $view->path,
            $view->title,
            $view->sourceKey,
            $view->sourceKind,
            '',
            '',
            '',
            '',
            '',
            12,
            75,
            '{}',
        );

        self::assertSame(1, $ingestor->ingest([$view]));
        self::assertSame(1, $ingestor->ingest([$engaged]));

        $session = $dbLayer->select('engaged_seconds, max_scroll_depth, bounced')
            ->from(AnalyticsSchema::SESSION_TABLE)
            ->execute()
            ->fetchAssoc();
        self::assertSame([
            'engaged_seconds'  => 12,
            'max_scroll_depth' => 75,
            'bounced'          => 0,
        ], $session);

        $global = $dbLayer->select('views, sessions, unique_count, bounces, engaged_seconds')
            ->from(AnalyticsSchema::DAY_ROLLUP_TABLE)
            ->where('dimension = :dimension')->setParameter('dimension', AnalyticsIngestor::DIMENSION_GLOBAL)
            ->execute()
            ->fetchAssoc();
        self::assertSame([
            'views'           => 1,
            'sessions'        => 1,
            'unique_count'    => 1,
            'bounces'         => 0,
            'engaged_seconds' => 12,
        ], $global);
    }

    public function testProjectsBlogDimensionsGoalsReadingDepthAndPerformance(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $this->createLegacyStorage($dbLayer);
        AnalyticsSchema::createEventStorage($dbLayer);
        $ingestor = new AnalyticsIngestor(
            $pdo,
            $dbLayer,
            new AnalyticsRepository($dbLayer),
            new AnalyticsReportCache(new NullAdapter()),
            new AnalyticsBlogProjector($dbLayer),
        );
        $time = (new \DateTimeImmutable('2026-08-30T12:15:00+00:00'))->getTimestamp();
        $properties = json_encode([
            'content_type' => 'post',
            'author'       => 'Alice',
            'section'      => 'PHP',
            'device'       => 'mobile',
            'browser'      => 'Chrome',
            'os'           => 'Android',
            'screen'       => 'small',
            'language'     => 'ru-RU',
            'published_at' => $time - 86400,
            'word_count'   => 1200,
        ], JSON_THROW_ON_ERROR);
        $view = $this->pageView(
            str_repeat('5', 32),
            str_repeat('d', 64),
            str_repeat('e', 64),
            '/post',
            $time,
            $properties,
        );
        $engaged = $this->derivedEvent(
            $view,
            str_repeat('6', 32),
            AnalyticsEvent::TYPE_ENGAGEMENT,
            $time + 31,
            engagementSeconds: 31,
            scrollDepth: 80,
            propertiesJson: $properties,
        );
        $finished = $this->derivedEvent(
            $view,
            str_repeat('7', 32),
            AnalyticsEvent::TYPE_ENGAGEMENT,
            $time + 40,
            scrollDepth: 100,
            propertiesJson: $properties,
        );
        $vitals = $this->derivedEvent(
            $view,
            str_repeat('8', 32),
            AnalyticsEvent::TYPE_CUSTOM,
            $time + 41,
            name: AnalyticsBlogProjector::EVENT_WEB_VITALS,
            propertiesJson: '{"lcp_ms":2100,"cls_milli":80,"inp_ms":180}',
        );
        $comment = $this->derivedEvent(
            $view,
            str_repeat('9', 32),
            AnalyticsEvent::TYPE_CUSTOM,
            $time + 42,
            name: 'comment.submit',
        );

        self::assertSame(5, $ingestor->ingest([$comment, $finished, $view, $vitals, $engaged]));

        $duplicate = $this->pageView(
            str_repeat('a', 32),
            $view->sessionKey,
            $view->pageKey,
            $view->path,
            $time + 1,
            $properties,
            $view->pageViewId,
        );
        self::assertSame(0, $ingestor->ingest([$duplicate]));
        self::assertSame(5, (int)$dbLayer->select('COUNT(*)')->from(AnalyticsSchema::EVENT_TABLE)->execute()->result());

        $pageView = $dbLayer->select('engaged_seconds, max_scroll_depth, engaged_30, read_75, read_100')
            ->from(AnalyticsSchema::PAGE_VIEW_TABLE)
            ->execute()
            ->fetchAssoc();
        self::assertSame([
            'engaged_seconds'  => 31,
            'max_scroll_depth' => 100,
            'engaged_30'       => 1,
            'read_75'          => 1,
            'read_100'         => 1,
        ], $pageView);

        $labels = $dbLayer->select('kind, label')
            ->from(AnalyticsSchema::DIMENSION_TABLE)
            ->orderBy('kind')
            ->execute()
            ->fetchAssocAll();
        self::assertCount(8, $labels);
        self::assertContains(['kind' => 'author', 'label' => 'Alice'], $labels);
        self::assertContains(['kind' => 'device', 'label' => 'mobile'], $labels);
        self::assertContains(['kind' => 'section', 'label' => 'PHP'], $labels);

        $goals = $dbLayer->query(
            'SELECT g.name, d.events FROM register_analytics_goal_day d '
            . 'INNER JOIN register_analytics_goal g ON g.goal_key = d.goal_key '
            . 'WHERE d.page_key = :page_key ORDER BY g.name',
            ['page_key' => AnalyticsIngestor::GLOBAL_KEY],
        )->fetchAssocAll();
        self::assertSame([
            ['name' => 'comment.submit', 'events' => 1],
            ['name' => AnalyticsBlogProjector::GOAL_ENGAGED_30, 'events' => 1],
            ['name' => AnalyticsBlogProjector::GOAL_READ_100, 'events' => 1],
            ['name' => AnalyticsBlogProjector::GOAL_READ_75, 'events' => 1],
        ], $goals);

        $performance = $dbLayer->select('sample_count, lcp_count, lcp_good, cls_count, cls_good, inp_count, inp_good')
            ->from(AnalyticsSchema::PERFORMANCE_DAY_TABLE)
            ->where('page_key = :page_key')->setParameter('page_key', AnalyticsIngestor::GLOBAL_KEY)
            ->execute()
            ->fetchAssoc();
        self::assertSame([
            'sample_count' => 1,
            'lcp_count'    => 1,
            'lcp_good'     => 1,
            'cls_count'    => 1,
            'cls_good'     => 1,
            'inp_count'    => 1,
            'inp_good'     => 1,
        ], $performance);
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

    private function pageView(
        string $id,
        string $sessionKey,
        string $pageKey,
        string $path,
        int $time,
        string $propertiesJson = '{}',
        ?string $pageViewId = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            $id,
            AnalyticsEvent::TYPE_PAGE_VIEW,
            $time,
            $time,
            str_repeat('f', 64),
            $sessionKey,
            $pageViewId ?? substr($id . $id, 0, 32),
            $pageKey,
            $path,
            ucfirst(ltrim($path, '/')),
            str_repeat('9', 64),
            'direct',
            '',
            '',
            '',
            '',
            '',
            0,
            0,
            $propertiesJson,
        );
    }

    private function derivedEvent(
        AnalyticsEvent $view,
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
            $view->visitorKey,
            $view->sessionKey,
            $view->pageViewId,
            $view->pageKey,
            $view->path,
            $view->title,
            $view->sourceKey,
            $view->sourceKind,
            $view->referrerHost,
            $view->utmSource,
            $view->utmMedium,
            $view->utmCampaign,
            $name,
            $engagementSeconds,
            $scrollDepth,
            $propertiesJson,
        );
    }
}
