<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\Analytics\AnalyticsRepository;
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

        $I->amOnPage('https://localhost/_analytics/counter.png');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'image/png');
    }

    public function honorsPrivacySignalsAndRejectsPublicAnalyticsData(\IntegrationTester $I): void
    {
        $I->sendRequestWithHeaders('https://localhost/', [
            'User-Agent' => 'Register privacy test',
            'DNT'        => '1',
        ]);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $hits    = $dbLayer->select('COUNT(*)')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', AnalyticsRepository::PAGE_CHANNEL)
            ->execute()
            ->result()
        ;
        $I->assertSame(0, (int)$hits);

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
