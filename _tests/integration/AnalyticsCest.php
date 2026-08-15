<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\Analytics\AnalyticsRepository;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;
use S2\Cms\Pdo\DbLayer;

final class AnalyticsCest
{
    public function recordsAggregatesWithoutRawAddresses(\IntegrationTester $I): void
    {
        $headers = [
            'User-Agent'      => 'Register integration browser',
            'X-Forwarded-For' => '192.0.2.42',
        ];
        $I->sendRequestWithHeaders('https://localhost/', $headers);
        $I->seeResponseCodeIs(200);
        $I->seeElement('meta[name="register-analytics-page"]');

        $fingerprintId = '0123456789abcdef0123456789abcdef';
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprintId,
            'trackPage'   => true,
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

        // Deleting the cookie does not create another visitor: the same browser fingerprint restores it.
        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprintId,
            'trackPage'   => false,
        ], headers: ['Origin' => 'https://localhost']);
        $recovered = $I->grabJson();
        $I->assertIsArray($recovered);
        $I->assertSame('fingerprint', $recovered['source']);
        $I->assertSame($resolved['token'], $recovered['token']);

        /** @var VisitorIdentityRepository $visitorRepository */
        $visitorRepository = $I->grabService(VisitorIdentityRepository::class);
        $I->assertSame(1, $visitorRepository->totalVisitors());

        $I->sendRequestWithHeaders('https://localhost/', $headers);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
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

        $storedFingerprint = $dbLayer->select('fingerprint_hash')
            ->from('register_visitor_fingerprint')
            ->execute()
            ->result()
        ;
        $I->assertIsString($storedFingerprint);
        $I->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $storedFingerprint);
        $I->assertNotSame($fingerprintId, $storedFingerprint);

        $I->amOnPage('https://localhost/_analytics/counter.png');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'image/png');

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Statistics');
        $I->see('Unique visitors', '.analytics-summary');
        $I->see('1', '.analytics-summary-value');
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
            'fingerprint' => 'cccccccccccccccccccccccccccccccc',
            'trackPage'   => true,
        ], headers: [
            'DNT'        => '1',
            'Sec-GPC'    => '1',
            'User-Agent' => 'Register privacy test',
        ]);

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
}
