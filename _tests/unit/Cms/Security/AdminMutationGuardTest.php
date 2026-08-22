<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\Request;

final class AdminMutationGuardTest extends Unit
{
    public function testUsesTheRealMethodInsteadOfAnOverride(): void
    {
        $guard = new AdminMutationGuard();

        self::assertTrue($guard->isPost(Request::create('/_admin/', Request::METHOD_POST)));
        self::assertFalse($guard->isPost(Request::create(
            '/_admin/',
            Request::METHOD_GET,
            server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => Request::METHOD_POST],
        )));
    }

    public function testAcceptsOnlyAnExactNonEmptyStringToken(): void
    {
        $guard = new AdminMutationGuard();
        $expected = hash('sha256', 'expected token');

        self::assertTrue($guard->hasValidCsrfToken(Request::create(
            '/_admin/',
            Request::METHOD_POST,
            ['csrf_token' => $expected],
        ), $expected));
        self::assertFalse($guard->hasValidCsrfToken(Request::create(
            '/_admin/',
            Request::METHOD_POST,
            ['csrf_token' => [$expected]],
        ), $expected));
        self::assertFalse($guard->hasValidCsrfToken(Request::create(
            '/_admin/',
            Request::METHOD_POST,
            ['csrf_token' => ''],
        ), $expected));
        self::assertFalse(AdminMutationGuard::tokensMatch('', ''));
    }

    public function testSupportsTheAdminYardTokenParameterOnlyWhenExplicitlySelected(): void
    {
        $guard = new AdminMutationGuard();
        $token = hash('sha256', 'form token');
        $request = Request::create(
            '/_admin/',
            Request::METHOD_POST,
            ['__csrf_token' => $token],
        );

        self::assertFalse($guard->hasValidCsrfToken($request, $token));
        self::assertTrue($guard->hasValidCsrfToken($request, $token, '__csrf_token'));
    }
}
