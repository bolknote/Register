<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace integration;

use \IntegrationTester;
use Symfony\Component\HttpFoundation\Response;

class RedirectCest
{
    public function tryToTest(IntegrationTester $I): void
    {
        $I->amOnPage('/redirect');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/redirected');
    }
}
