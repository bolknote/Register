<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Monitoring\QueryProfilerLog;
use Register\Core\Monitoring\QueryProfilerState;
use Register\Core\Monitoring\RequestQueryProfiler;

final class QueryProfilerSecurityCest
{
    public function _after(\IntegrationTester $I): void
    {
        $state = $I->grabAdminService(QueryProfilerState::class);
        $log = $I->grabAdminService(QueryProfilerLog::class);
        if (!$state instanceof QueryProfilerState || !$log instanceof QueryProfilerLog) {
            throw new \LogicException('Query profiler services are unavailable.');
        }

        $state->stop();
        $log->clear();
    }

    public function testAdministratorCanCaptureAndClearRedactedProfiles(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemStatus');
        $I->seeElement('form.query-profiler-actions[method="post"]');

        $csrfToken = $I->grabValueFrom('form.query-profiler-actions input[name="csrf_token"]');
        $I->assertIsString($csrfToken);
        $I->assertNotSame('', $csrfToken);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_query_profiler');
        $I->seeResponseCodeIs(405);

        $I->sendPost('https://localhost/_admin/ajax.php?action=register_query_profiler', [
            'csrf_token' => 'invalid',
            'command'    => 'start_300',
        ]);
        $I->seeResponseCodeIs(403);

        $I->sendPost('https://localhost/_admin/ajax.php?action=register_query_profiler', [
            'csrf_token' => $csrfToken,
            'command'    => 'start_300',
        ]);
        $I->seeResponseCodeIs(303);

        $I->amOnPage('https://localhost/?profile-secret=must-not-be-stored');
        $I->seeResponseCodeIs(200);

        $requestProfiler = $I->grabService(RequestQueryProfiler::class);
        if (!$requestProfiler instanceof RequestQueryProfiler) {
            throw new \LogicException('The request query profiler is unavailable.');
        }

        $requestProfiler->record([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/?profile-secret=must-not-be-stored',
        ], 200);
        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemStatus');
        $I->see('GET /', '.query-profiler-stat-item');
        $I->see('HTTP requests captured:', '.query-profiler-stat-item');
        $I->dontSee('must-not-be-stored', '.query-profiler-stat-item');

        $I->sendPost('https://localhost/_admin/ajax.php?action=register_query_profiler', [
            'csrf_token' => $csrfToken,
            'command'    => 'stop',
        ]);
        $I->seeResponseCodeIs(303);
        $I->sendPost('https://localhost/_admin/ajax.php?action=register_query_profiler', [
            'csrf_token' => $csrfToken,
            'command'    => 'clear',
        ]);
        $I->seeResponseCodeIs(303);
    }
}
