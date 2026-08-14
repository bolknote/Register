<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\LoginRateLimiter;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthManagerRedirectTest extends Unit
{
    public function testHttpsRedirectUsesConfiguredHost(): void
    {
        $authManager = new AuthManager(
            self::createStub(DbLayer::class),
            self::createStub(PermissionChecker::class),
            new RequestStack(),
            self::createStub(TemplateRenderer::class),
            self::createStub(Translator::class),
            new LoginRateLimiter(
                self::createStub(DbLayer::class),
                new SpamIdentityHasher(str_repeat('s', 32)),
                new NullLogger(),
            ),
            '/blog',
            'http://trusted.example/blog',
            's2_cookie',
            true,
        );

        $request = Request::create('http://attacker.example/blog/_admin/index.php?entity=Dashboard');

        $response = $authManager->checkAuth($request);
        self::assertNotNull($response);
        self::assertSame(
            'https://trusted.example/blog/_admin/index.php?entity=Dashboard',
            $response->headers->get('Location'),
        );
    }
}
