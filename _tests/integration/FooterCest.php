<?php

declare(strict_types = 1);

namespace integration;

use Symfony\Component\HttpFoundation\Response;

final class FooterCest
{
    public function testEngineCreditLinksToRegisterRepository(\IntegrationTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('.engine-credit a[href="https://github.com/bolknote/Register"]');
        $I->dontSeeElement('.engine-credit a[href="https://github.com/parpalak/s2"]');
    }
}
