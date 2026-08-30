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
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            $id,
            AnalyticsEvent::TYPE_PAGE_VIEW,
            $time,
            $time,
            str_repeat('f', 64),
            $sessionKey,
            substr($id . $id, 0, 32),
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
            '{}',
        );
    }
}
