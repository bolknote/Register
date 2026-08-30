<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\Analytics\AnalyticsRepository;
use Register\Module\Analytics\AnalyticsMaintenanceTask;
use Register\Module\Analytics\AnalyticsPresenceStore;
use Register\Module\Analytics\AnalyticsReportRepository;
use Register\Module\Analytics\AnalyticsSchema;
use Register\Module\Analytics\AnalyticsSpool;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueExecutionBudget;
use Symfony\Component\Filesystem\Filesystem;

final class AnalyticsCest
{
    public function _before(\IntegrationTester $I): void
    {
        /** @var AnalyticsSpool $spool */
        $spool = $I->grabService(AnalyticsSpool::class);
        (new Filesystem())->remove($spool->directory());
    }

    public function recordsAggregatesWithoutRawAddresses(\IntegrationTester $I): void
    {
        $headers = [
            'User-Agent'      => 'Register integration browser',
            'X-Forwarded-For' => '192.0.2.42',
        ];
        $I->sendRequestWithHeaders('https://localhost/', $headers);
        $I->seeResponseCodeIs(200);
        $I->seeElement('meta[name="register-analytics"]');
        $I->seeElement('script[src*="analytics/collector.js?v=5.0"]');

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $unresolvedSummary = $dbLayer->select('hits, unique_count')
            ->from('register_analytics_daily')
            ->where('day = :day')->setParameter('day', date('Y-m-d'))
            ->andWhere('channel = :channel')->setParameter('channel', AnalyticsRepository::PAGE_CHANNEL)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertFalse($unresolvedSummary, 'An unresolved HTML fetch must not write analytics.');

        // A dashboard request may cache an empty report before the first event arrives.
        // Ingestion must invalidate that result immediately instead of relying on a TTL.
        /** @var AnalyticsReportRepository $reportRepository */
        $reportRepository = $I->grabService(AnalyticsReportRepository::class);
        $I->assertSame([], $reportRepository->dailyOverview());

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: [
            'Origin'     => 'https://localhost',
            'User-Agent' => 'Register integration browser',
        ]);
        $I->seeResponseCodeIs(200);

        $resolved = $I->grabJson();
        $I->assertIsArray($resolved);
        $I->assertSame('new', $resolved['source']);
        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $resolved['token']);

        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $I->assertSame($resolved['token'], $I->grabTestCookie($identityManager->cookieName()));

        // Browser storage can restore the signed identity after the cookie is removed.
        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'token'     => $resolved['token'],
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $recovered = $I->grabJson();
        $I->assertIsArray($recovered);
        $I->assertSame('storage', $recovered['source']);
        $I->assertSame($resolved['token'], $recovered['token']);

        /** @var VisitorIdentityRepository $visitorRepository */
        $visitorRepository = $I->grabService(VisitorIdentityRepository::class);
        $I->assertSame(1, $visitorRepository->totalVisitors());

        $sessionId = bin2hex(random_bytes(16));
        $this->sendPageView($I, '/', $sessionId, headers: $headers);
        $this->drainAnalytics($I);

        // A warmed page-cache response still contains the browser collector and is counted by it.
        $I->sendRequestWithHeaders('https://localhost/', $headers);
        $I->seeElement('meta[name="register-analytics"]');

        $pageViewId = $this->sendPageView($I, '/', $sessionId, headers: $headers);
        $this->drainAnalytics($I);

        $liveQuery = http_build_query([
            'cursor'                => '0',
            'region'                => ['site-account'],
            'analytics_pageview_id' => $pageViewId,
            'analytics_session_id'  => $sessionId,
            'analytics_path'        => '/',
            'analytics_title'       => 'Register',
        ]);
        $I->sendRequestWithHeaders('https://localhost/_live?' . $liveQuery, $headers);
        $I->seeResponseCodeIs(200);
        /** @var AnalyticsPresenceStore $presenceStore */
        $presenceStore = $I->grabService(AnalyticsPresenceStore::class);
        $presence = $presenceStore->snapshot(time());
        $matchingPresence = array_values(array_filter(
            $presence,
            static fn(array $entry): bool => $entry['path'] === '/' && $entry['title'] === 'Register',
        ));
        $I->assertNotSame([], $matchingPresence);

        $summary = $dbLayer->select('hits, unique_count')
            ->from('register_analytics_daily')
            ->where('day = :day')->setParameter('day', date('Y-m-d'))
            ->andWhere('channel = :channel')->setParameter('channel', AnalyticsRepository::PAGE_CHANNEL)
            ->execute()
            ->fetchAssoc()
        ;

        $I->assertNotFalse($summary);
        $I->assertSame(2, (int)$summary['hits']);
        $I->assertSame(1, (int)$summary['unique_count']);

        $fingerprint = $dbLayer->select('fingerprint')
            ->from('register_analytics_visitor')
            ->where('channel = :channel')->setParameter('channel', AnalyticsRepository::PAGE_CHANNEL)
            ->execute()
            ->result()
        ;
        $I->assertIsString($fingerprint);
        $I->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fingerprint);
        $I->assertStringNotContainsString('192.0.2.42', $fingerprint);

        $events = $dbLayer->select('visitor_key, properties_json')
            ->from(AnalyticsSchema::EVENT_TABLE)
            ->execute()
            ->fetchAssocAll();
        $I->assertCount(2, $events);
        foreach ($events as $event) {
            $I->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$event['visitor_key']);
            $I->assertStringNotContainsString('192.0.2.42', (string)$event['properties_json']);
            $I->assertStringNotContainsString('Register integration browser', (string)$event['properties_json']);
        }

        $session = $dbLayer->select('pageviews, bounced')
            ->from(AnalyticsSchema::SESSION_TABLE)
            ->execute()
            ->fetchAssoc();
        $I->assertNotFalse($session);
        $I->assertSame(2, (int)$session['pageviews']);
        $I->assertSame(0, (int)$session['bounced']);

        $I->amOnPage('https://localhost/_analytics/counter.png');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'image/png');

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_report&report=daily');
        $I->seeResponseCodeIs(200);

        $report = $I->grabJson();
        $I->assertIsArray($report);
        $I->assertTrue($report['success']);
        $I->assertSame(2, $report['data'][0]['views']);
        $I->assertSame(1, $report['data'][0]['sessions']);
        $I->assertSame(0, $report['data'][0]['bounces']);

        $today = date('Y-m-d');
        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_report&report=pages&from=' . $today . '&to=' . $today);
        $I->seeResponseCodeIs(200);

        $pages = $I->grabJson();
        $I->assertIsArray($pages);
        $I->assertSame('/', $pages['data'][0]['path']);
        $I->assertSame(2, $pages['data'][0]['views']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_report&report=dashboard&from=' . $today . '&to=' . $today);

        $dashboard = $I->grabJson();
        $I->assertIsArray($dashboard);
        $I->assertTrue($dashboard['success']);
        $I->assertSame(2, $dashboard['data']['summary']['views']);
        $I->assertArrayHasKey('technology', $dashboard['data']);
        $I->assertArrayHasKey('realtime', $dashboard['data']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_report&report=pages&format=csv&from=' . $today . '&to=' . $today);
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/csv; charset=UTF-8');
        $I->seeHttpHeader('Content-Disposition', 'attachment; filename="register-analytics-pages-' . $today . '-' . $today . '.csv"');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Statistics');
        $I->see('Blog analytics', '.register-analytics h2');
        $I->see('Unique visitors', '.analytics-summary');
        $I->see('1', '.analytics-summary-value');
        $I->see('Sessions', '.analytics-summary-list');
        $I->seeElement('[data-analytics-realtime-visitors]');
        $I->seeElement('.analytics-range-selector [data-analytics-range-days="30"][aria-pressed="true"]');
        $I->seeElement('[data-analytics-panel="pages"][hidden]');
        $I->seeElement('[data-analytics-panel="sessions"][hidden]');
        $I->seeElement('.analytics-ranking-grid[hidden]');
        $I->seeElement('script[src*="analytics/charts.js?v=5.0"]');
    }

    public function ignoresBrowserPrivacySignalsAndRejectsPublicAnalyticsData(\IntegrationTester $I): void
    {
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $I->resetTestCookie($identityManager->cookieName());

        $I->sendRequestWithHeaders('https://localhost/', [
            'User-Agent' => 'Register privacy test',
            'DNT'        => '1',
        ]);
        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: [
            'DNT'        => '1',
            'Sec-GPC'    => '1',
            'User-Agent' => 'Register privacy test',
        ]);
        $this->sendPageView($I, '/', bin2hex(random_bytes(16)), headers: [
            'DNT'        => '1',
            'Sec-GPC'    => '1',
            'User-Agent' => 'Register privacy test',
        ]);
        $this->drainAnalytics($I);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $summary = $dbLayer->select('hits, unique_count')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', AnalyticsRepository::PAGE_CHANNEL)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertNotFalse($summary);
        $I->assertSame(1, (int)$summary['hits']);
        $I->assertSame(1, (int)$summary['unique_count']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_series&channel=page');
        $I->seeResponseCodeIs(401);
        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_report&report=daily');
        $I->seeResponseCodeIs(401);
    }

    public function servesSeriesOnlyToAuthorizedAdministrators(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_series&channel=page');
        $I->seeResponseCodeIs(200);

        $data = $I->grabJson();
        $I->assertIsArray($data);
        $I->assertTrue($data['success']);
        $I->assertSame([], $data['series']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_analytics_series&channel=raw');
        $I->seeResponseCodeIs(400);
    }

    /** @param array<string, string> $headers */
    private function sendPageView(
        \IntegrationTester $I,
        string $path,
        string $sessionId,
        array $headers,
    ): string {
        $pageViewId = bin2hex(random_bytes(16));
        $I->sendJson('https://localhost/_analytics/collect', [
            'v'      => 1,
            'events' => [[
                'id'          => bin2hex(random_bytes(16)),
                'type'        => 'pageview',
                'occurred_at' => time() * 1000,
                'session_id'  => $sessionId,
                'pageview_id' => $pageViewId,
                'path'        => $path,
                'title'       => 'Register',
                'referrer'    => '',
                'utm'         => [],
            ]],
        ], headers: ['Origin' => 'https://localhost'] + $headers);
        $I->seeResponseCodeIs(204);
        return $pageViewId;
    }

    private function drainAnalytics(\IntegrationTester $I): void
    {
        /** @var AnalyticsMaintenanceTask $maintenance */
        $maintenance = $I->grabService(AnalyticsMaintenanceTask::class);
        $maintenance->runIfDue(time() + 60, new QueueExecutionBudget(2.0));
    }
}
