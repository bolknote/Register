<?php

declare(strict_types = 1);

namespace integration;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FooterCest
{
    public function testEngineCreditLinksToRegisterRepository(\IntegrationTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('head link[rel="alternate"][type="application/rss+xml"][href="/rss"]');
        $I->seeElement('#copyright > .footer-primary > .copyright-text');
        $I->seeElement('#copyright > .footer-primary > .footer-rss[href="/rss"][aria-label]');
        $I->seeElement('.footer-rss img[src="/_assets/register/rss-badge.svg"][alt=""]');
        $I->seeElement('#copyright > .engine-credit');
        $I->seeElement('.engine-credit a[href="https://github.com/bolknote/Register"]');
    }

    public function testPerformanceInfoIsVisibleOnlyToAdministrator(\IntegrationTester $I): void
    {
        $application = $I->createApplication(['debug' => true]);
        $sharedPdo   = $I->grabService(\PDO::class);
        $application->container->decorate(\PDO::class, static fn(): \PDO => $sharedPdo);
        $cookieName = $application->container->getStringParameter('cookie_name') . '_c';

        $anonymousResponse = $application->handle(Request::create('https://localhost/'));
        $I->assertStringNotContainsString('class="technical-data"', (string)$anonymousResponse->getContent());

        $I->login('editor', 'editor');

        $editorCookie = $I->grabTestCookie($cookieName);
        $I->assertNotNull($editorCookie);
        $editorResponse = $application->handle(Request::create(
            'https://localhost/',
            cookies: [$cookieName => $editorCookie],
        ));
        $I->assertStringNotContainsString('class="technical-data"', (string)$editorResponse->getContent());

        $I->logout();
        $I->login('admin', 'admin');

        $adminCookie = $I->grabTestCookie($cookieName);
        $I->assertNotNull($adminCookie);
        $adminResponse = $application->handle(Request::create(
            'https://localhost/',
            cookies: [$cookieName => $adminCookie],
        ));
        $I->assertStringContainsString('class="technical-data"', (string)$adminResponse->getContent());
    }
}
