<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use Register\Core\Security\Http\SameOriginRequestGuard;
use Symfony\Component\HttpFoundation\Request;

final class SameOriginRequestGuardTest extends Unit
{
    public function testAllowsSameOriginAndRefererFallback(): void
    {
        $originRequest = Request::create(
            'https://example.com/_admin/index.php',
            Request::METHOD_POST,
            server: ['HTTP_ORIGIN' => 'https://EXAMPLE.com:443'],
        );
        self::assertNull($this->guard()->violation($originRequest));

        $refererRequest = Request::create(
            'https://example.com/_admin/index.php',
            Request::METHOD_POST,
            server: ['HTTP_REFERER' => 'https://example.com/_admin/?entity=User'],
        );
        self::assertNull($this->guard()->violation($refererRequest));
    }

    public function testRejectsForeignAndSiblingOrigins(): void
    {
        $foreignRequest = Request::create(
            'https://example.com/_admin/index.php',
            Request::METHOD_POST,
            server: ['HTTP_ORIGIN' => 'https://example.com.attacker.test'],
        );
        self::assertSame('The request origin is not allowed.', $this->guard()->violation($foreignRequest));

        $siblingRequest = Request::create(
            'https://admin.example.com/_admin/index.php',
            Request::METHOD_POST,
            server: [
                'HTTP_ORIGIN'         => 'https://blog.example.com',
                'HTTP_SEC_FETCH_SITE' => 'same-site',
            ],
        );
        self::assertSame('A same-origin request is required.', $this->guard()->violation($siblingRequest));
    }

    public function testAllowsSafeMethodsAndMissingCompatibilityHeaders(): void
    {
        $getRequest = Request::create(
            'https://example.com/_admin/index.php',
            Request::METHOD_GET,
            server: ['HTTP_ORIGIN' => 'https://attacker.test'],
        );
        self::assertNull($this->guard()->violation($getRequest));

        $legacyPost = Request::create('https://example.com/_admin/index.php', Request::METHOD_POST);
        self::assertNull($this->guard()->violation($legacyPost));
    }

    public function testRejectsNullAndMalformedOrigins(): void
    {
        foreach (['null', 'https://example.com@attacker.test', 'javascript:alert(1)', 'https://example.com:99999'] as $origin) {
            $request = Request::create(
                'https://example.com/_admin/index.php',
                Request::METHOD_POST,
                server: ['HTTP_ORIGIN' => $origin],
            );
            self::assertSame('The request origin is not allowed.', $this->guard()->violation($request));
        }
    }

    public function testRejectsCrossSiteFetchMetadataEvenWithMatchingOrigin(): void
    {
        $request = Request::create(
            'https://example.com/_admin/index.php',
            Request::METHOD_POST,
            server: [
                'HTTP_ORIGIN'         => 'https://example.com',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ],
        );

        self::assertSame('A same-origin request is required.', $this->guard()->violation($request));
    }

    public function testSharedPublicTokensRequireIndependentBrowserEvidence(): void
    {
        foreach ([[], ['HTTP_SEC_FETCH_SITE' => 'none'], ['HTTP_SEC_FETCH_SITE' => 'unrecognized']] as $server) {
            $request = Request::create('https://example.com/auth/password', Request::METHOD_POST, server: $server);

            self::assertSame('A same-origin request is required.', $this->guard()->violation($request, requireBrowserEvidence: true));
        }

        foreach ([
            ['HTTP_ORIGIN' => 'https://example.com'],
            ['HTTP_REFERER' => 'https://example.com/article'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        ] as $server) {
            $request = Request::create('https://example.com/auth/password', Request::METHOD_POST, server: $server);

            self::assertNull($this->guard()->violation($request, requireBrowserEvidence: true));
        }
    }

    private function guard(): SameOriginRequestGuard
    {
        return new SameOriginRequestGuard();
    }
}
