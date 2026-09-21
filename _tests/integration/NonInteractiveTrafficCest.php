<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Pdo\PDO;
use Symfony\Component\HttpFoundation\Response;

final class NonInteractiveTrafficCest
{
    public function botRenderedPageDoesNotBootstrapInteractiveRequests(\IntegrationTester $I): void
    {
        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; Baiduspider-render/2.0)'];
        $I->sendRequestWithHeaders('https://localhost/', $headers);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        foreach (['register-analytics', 'register-visitor', 'register-live-updates'] as $name) {
            $I->dontSeeElement('meta[name="' . $name . '"]');
        }

        foreach (['analytics/collector.js', 'visitor/identity.js', 'reactions/reactions.js', 'live-updates.js'] as $script) {
            $I->assertStringNotContainsString($script, $I->grabResponse());
        }
    }

    public function botApiRequestsReturnBeforeDatabaseWork(\IntegrationTester $I): void
    {
        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; Baiduspider-render/2.0)'];

        $I->sendJson('https://localhost/_visitor/resolve', [], headers: [
            ...$headers,
            'Origin' => 'https://localhost',
        ]);
        $this->assertCheapBotResponse($I);
        $I->assertNull($I->grabHttpHeader('Set-Cookie'));

        $I->sendJson('https://localhost/_analytics/collect', [], headers: [
            ...$headers,
            'Origin' => 'https://localhost',
        ]);
        $this->assertCheapBotResponse($I);

        $I->sendRequestWithHeaders('https://localhost/_reactions?content=post%3A1', $headers);
        $this->assertCheapBotResponse($I);

        $I->sendRequestWithHeaders('https://localhost/_live?cursor=0&region%5B%5D=posts%3A0', $headers);
        $this->assertCheapBotResponse($I);
    }

    private function assertCheapBotResponse(\IntegrationTester $I): void
    {
        $I->seeResponseCodeIs(Response::HTTP_NO_CONTENT);
        $I->assertSame('', $I->grabResponse());
        $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }
}
