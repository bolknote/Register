<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Model\AuthManager;
use Register\Core\Model\LoginRateLimiter;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
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
            new SecurityAuditLogger('php://memory', new SpamIdentityHasher(str_repeat('a', 32))),
            '/blog',
            'http://trusted.example/blog',
            'register_cookie',
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
