<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentIdAllocator;
use Register\Auth\CommentNotificationRepository;
use Register\Auth\PublicAuthController;
use Register\Auth\MagicLinkService;
use Register\Auth\MagicLinkRateLimiter;
use Register\Auth\PublicAuthFormToken;
use Register\Auth\PublicAuthRepository;
use Register\Auth\PublicAuthSchema;
use Register\Auth\PublicAuthSettings;
use Register\Auth\PublicOAuthClient;
use Register\Auth\PendingCommentRecovery;
use Register\Core\Comment\CommentHtml;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Register\Comment\CommentSchema;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Controller\Comment\PendingEmailComment;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\SessionAudience;
use Register\Core\Comment\SpamDetectorReport;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\QueryResult;
use Register\Core\Pdo\QueryBuilder\InsertBuilder;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientInterface;
use Register\Core\HttpClient\HttpResponse;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Http\Cache\EarlyPageCache;
use Register\Core\Http\Cache\PageCachePools;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Core\Model\UrlBuilder;
use Register\Module\VisitorIdentity\Manifest as VisitorIdentityManifest;
use Register\Module\VisitorIdentity\VisitorIdentityManager;

final class PublicAuthCest
{
    public function _before(\IntegrationTester $I): void
    {
        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '1');
    }

    public function testGuestFormTokenKeepsPageEtagStableWithinAnHour(\IntegrationTester $I): void
    {
        /** @var PublicAuthFormToken $formToken */
        $formToken = $I->grabService(PublicAuthFormToken::class);
        $hour = 1_700_000_000 - (1_700_000_000 % 3600);

        $first = $formToken->issue($hour + 1);
        $I->assertSame($first, $formToken->issue($hour + 3599));
        $I->assertNotSame($first, $formToken->issue($hour + 3600));
        $I->assertTrue($formToken->matches($first, $hour + 3599));
    }

    public function testCachedSignInFormsRefreshInBothCacheLayersBeforeTheirTokenExpires(\IntegrationTester $I): void
    {
        $now = time() - 90_000;
        $clock = static function () use (&$now): int { return $now; };
        $application = $I->createApplication();
        $container = $application->container;
        $pdo = $I->grabService(\PDO::class);
        $container->decorate(\PDO::class, static fn(): mixed => $pdo);
        $container->decorate(PublicAuthFormToken::class, static fn(): PublicAuthFormToken => new PublicAuthFormToken(
            $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_ANTISPAM_SECRET'), $clock,
        ));
        $container->decorate(BlogPageCache::class, static function () use ($container, $clock): BlogPageCache {
            $pools = $container->get(PageCachePools::class);
            return new BlogPageCache($pools->persistent, hotCache: $pools->hot, clock: $clock);
        });
        $request = static fn(): Request => Request::create('https://localhost/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 expiry test']);
        $formToken = $container->get(PublicAuthFormToken::class);
        $firstToken = $formToken->issue();
        $first = $application->handle($request());
        $I->assertSame(200, $first->getStatusCode());
        $I->assertStringContainsString($firstToken, (string)$first->getContent());
        $deadline = (int)$first->headers->get(EarlyPageCache::DEADLINE_HEADER);
        $I->assertGreaterThan($now, $deadline);
        $I->assertLessThanOrEqual($formToken->issueWithExpiry()['expires'], $deadline);
        $warm = $application->handle($request());
        $I->assertSame('hit', $warm->headers->get('X-Register-Page-Cache'));
        $I->assertSame((string)$deadline, $warm->headers->get(EarlyPageCache::DEADLINE_HEADER));

        $early = $container->get(EarlyPageCache::class);
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost', 'HTTPS' => 'on'];
        $warm->prepare($request());
        $I->assertTrue($early->store($server, $warm->headers->all(), (string)$warm->getContent(), validUntil: $deadline, now: $now), $early->decision());
        $I->assertNotNull($early->lookup($server, $deadline - 1));

        $now = $formToken->issueWithExpiry()['expires'] + 1;
        $I->assertFalse($formToken->matches($firstToken));
        $I->assertNull($early->lookup($server, $now));
        $fresh = $application->handle($request());
        $freshToken = $formToken->issue();
        $I->assertSame('miss', $fresh->headers->get('X-Register-Page-Cache'));
        $I->assertStringNotContainsString($firstToken, (string)$fresh->getContent());
        $I->assertStringContainsString($freshToken, (string)$fresh->getContent());
        $I->assertTrue($formToken->matches($freshToken));

        $signedIn = $application->handle(Request::create('https://localhost/auth/password', 'POST', [
            'login' => 'admin', 'pass' => 'admin', 'auth_token' => $freshToken, 'return_path' => '/',
        ], server: ['HTTP_ORIGIN' => 'https://localhost', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        $I->assertSame(200, $signedIn->getStatusCode());
        $I->assertTrue(json_decode((string)$signedIn->getContent(), true, flags: JSON_THROW_ON_ERROR)['success']);
    }

    public function testGuestSeesOnlyConfiguredMethods(\IntegrationTester $I): void
    {
        $I->amOnPage('https://localhost/');

        $I->seeElement('.public-auth-login-button[rel="nofollow"][data-public-auth-open][data-register-native-navigation]');
        $I->seeElement('#public-auth-dialog .public-auth-email-form');
        $I->seeElement('#public-auth-dialog .public-auth-password-form');
        $I->seeElement('#public-auth-dialog [data-public-auth-mode-panel="password"][hidden]');
        $I->seeElement('#public-auth-dialog [data-public-auth-mode-open="password"]');
        $I->dontSeeElement('#public-auth-dialog .public-auth-name-section');
        $I->dontSeeElement('#public-auth-dialog .public-auth-provider-vk');
        $I->dontSeeElement('#public-auth-dialog .public-auth-provider-yandex');
    }

    public function testConfiguredProvidersUseOneCompactGrid(\IntegrationTester $I): void
    {
        $I->setConfigValue(PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY, 'vk-test-client');
        $I->setConfigValue(PublicAuthSettings::YANDEX_CLIENT_ID_CONFIG_KEY, 'yandex-test-client');
        $I->setConfigValue(PublicAuthSettings::YANDEX_CLIENT_SECRET_CONFIG_KEY, 'yandex-test-secret');

        $I->amOnPage('https://localhost/');

        $I->seeElement('.public-auth-email-form + .public-auth-mode-switch');
        $I->seeElement('.public-auth-mode-switch + .public-auth-divider + .public-auth-providers');
        $I->assertCount(4, $I->grabMultiple('#public-auth-dialog .public-auth-providers .public-auth-provider'));
        $I->seeElement('#public-auth-dialog .public-auth-provider-vk');
        $I->seeElement('#public-auth-dialog .public-auth-provider-yandex');
        $I->seeElement('#public-auth-dialog .public-auth-provider-mail');
        $I->seeElement('#public-auth-dialog .public-auth-provider-ok');
        $I->assertCount(4, $I->grabMultiple('#public-auth-dialog form.public-auth-provider-form[method="post"]'));
        $I->seeElement('#public-auth-dialog .public-auth-provider-yandex[type="submit"]');
        $I->dontSeeElement('#public-auth-dialog .public-auth-more-providers');
    }

    public function testPasswordSignInAndPublicLogoutUseTheSharedSession(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $resolved = $I->grabJson();
        $I->assertIsArray($resolved);
        $visitorId = $identityManager->visitorIdFromToken((string)($resolved['token'] ?? ''));
        $I->assertNotNull($visitorId);

        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabValueFrom('.public-auth-password-form input[name="auth_token"]');
        $I->sendAjaxPostRequest('https://localhost/auth/password', [
            'login'       => 'admin',
            'pass'        => 'admin',
            'remember_me' => '1',
            'auth_token'  => $token,
            'return_path' => '/',
        ], headers: ['Origin' => 'https://localhost']);

        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);
        $I->assertJsonSubResponseEquals('/', ['redirect']);
        $I->assertNotNull($I->grabTestCookie('register_cookie_904732485', '/_admin/'));
        $I->assertSame(SessionAudience::ADMIN->value, $dbLayer
            ->select('audience')
            ->from('users_online')
            ->where("login = 'admin'")
            ->execute()
            ->result());
        $I->assertSame(1, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(VisitorIdentityManifest::USER_LINK_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', $this->userId($dbLayer, 'admin'))
            ->execute()
            ->result());
        $I->amOnPage('https://localhost/');
        $I->seeElement('.public-auth-user-menu');
        $I->see('admin', '.public-auth-user-menu');
        $I->seeElement('.public-auth-menu-item[href*="/_admin/index.php"]');

        $logoutToken = (string)$I->grabValueFrom('.public-auth-logout-form input[name="csrf_token"]');
        $I->sendAjaxPostRequest('https://localhost/auth/logout', [
            'csrf_token' => $logoutToken,
            'return_path' => '/',
        ]);
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/');
        $I->seeElement('.public-auth-login-button');
        $I->dontSeeElement('.public-auth-user-menu');
    }

    public function testOneBrowserCanBeAssociatedWithSeveralAuthenticatedAccounts(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $resolved = $I->grabJson();
        $I->assertIsArray($resolved);
        $visitorId = $identityManager->visitorIdFromToken((string)($resolved['token'] ?? ''));
        $I->assertNotNull($visitorId);

        foreach (['admin', 'author'] as $login) {
            $I->amOnPage('https://localhost/');
            $token = (string)$I->grabValueFrom('.public-auth-password-form input[name="auth_token"]');
            $I->sendAjaxPostRequest('https://localhost/auth/password', [
                'login'       => $login,
                'pass'        => $login,
                'auth_token'  => $token,
                'return_path' => '/',
            ], headers: ['Origin' => 'https://localhost']);
            $I->seeResponseCodeIs(200);

            $I->amOnPage('https://localhost/');
            $logoutToken = (string)$I->grabValueFrom('.public-auth-logout-form input[name="csrf_token"]');
            $I->sendAjaxPostRequest('https://localhost/auth/logout', [
                'csrf_token'  => $logoutToken,
                'return_path' => '/',
            ]);
            $I->seeResponseCodeIs(200);
        }

        $links = $dbLayer
            ->select('user_id')
            ->from(VisitorIdentityManifest::USER_LINK_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->orderBy('user_id')
            ->execute()
            ->fetchAssocAll()
        ;
        $I->assertSame([
            ['user_id' => $this->userId($dbLayer, 'author')],
            ['user_id' => $this->userId($dbLayer, 'admin')],
        ], array_map(
            static fn(array $row): array => ['user_id' => (int)$row['user_id']],
            $links,
        ));
    }

    public function testPasswordSignInRejectsAnExpiredFormToken(\IntegrationTester $I): void
    {
        $I->resetTestCookie('register_cookie_904732485_c');
        $I->sendAjaxPostRequest('https://localhost/auth/password', [
            'login'       => 'admin',
            'pass'        => 'admin',
            'auth_token'  => 'invalid',
            'return_path' => '/',
        ], headers: ['Origin' => 'https://localhost']);

        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals(false, ['success']);
        $I->assertNull($I->grabTestCookie('register_cookie_904732485_c'));
    }

    public function testPublicLoginActionsRejectForeignAndUnverifiableSources(\IntegrationTester $I): void
    {
        $I->amOnPage('https://localhost/');
        $token = (string)$I->grabValueFrom('.public-auth-password-form input[name="auth_token"]');
        $controller = $I->grabService(PublicAuthController::class);
        $db = $I->grabService(DbLayer::class);
        $sessionCount = (int)$db->select('COUNT(*)')->from('users_online')->execute()->result();

        foreach (['password', 'email', 'oauth_start'] as $action) {
            foreach ([
                ['HTTP_ORIGIN' => 'https://localhost.attacker.test'],
                ['HTTP_ORIGIN' => 'https://sibling.localhost', 'HTTP_SEC_FETCH_SITE' => 'same-site'],
                ['HTTP_ORIGIN' => 'null'],
                ['HTTP_REFERER' => 'https://attacker.test/login'],
                ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://localhost'],
                ['HTTP_SEC_FETCH_SITE' => 'none'],
                [],
            ] as $server) {
                $request = Request::create('https://localhost/auth/' . $action, 'POST', [
                    'auth_token' => $token, 'login' => 'author', 'pass' => 'author',
                    'email' => 'csrf@example.test', 'return_path' => '/',
                ], server: $server);
                $request->attributes->add(['auth_action' => $action, 'provider' => 'vk']);
                $response = $controller->handle($request);

                $I->assertSame(403, $response->getStatusCode(), $action . ': ' . json_encode($server));
                $I->assertSame([], $response->headers->getCookies());
            }
        }

        $I->assertSame($sessionCount, (int)$db->select('COUNT(*)')->from('users_online')->execute()->result());
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(PublicAuthSchema::FLOWS_TABLE)->execute()->result());
        $I->assertSame([], $I->grabPublicAuthMails());
    }

    public function testPublicLoginAcceptsVerifiedOriginAndBrowserMetadata(\IntegrationTester $I): void
    {
        $controller = $I->grabService(PublicAuthController::class);
        $token = $I->grabService(PublicAuthFormToken::class)->issue();
        foreach ([
            ['HTTP_ORIGIN' => 'https://LOCALHOST:443'],
            ['HTTP_REFERER' => 'https://localhost/article'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        ] as $server) {
            $request = Request::create('https://localhost/auth/password', 'POST', [
                'auth_token' => $token, 'login' => 'author', 'pass' => 'author', 'return_path' => '/',
            ], server: $server);
            $request->attributes->set('auth_action', 'password');
            $response = $controller->handle($request);

            $I->assertSame(302, $response->getStatusCode());
            $I->assertNotSame([], $response->headers->getCookies());
        }
    }

    public function testOAuthCallbackRequiresItsOriginalBrowserWithoutConsumingOtherFlows(\IntegrationTester $I): void
    {
        $I->setConfigValue(PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY, 'vk-test-client');
        $I->setConfigValue(PublicAuthSettings::YANDEX_CLIENT_ID_CONFIG_KEY, 'yandex-test-client');
        $I->setConfigValue(PublicAuthSettings::YANDEX_CLIENT_SECRET_CONFIG_KEY, 'yandex-test-secret');

        $http = new class implements HttpClientInterface {
            public int $requests = 0;

            /** @var array<string, string> */
            public array $challenges = [];

            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
            {
                ++$this->requests;
                $profile = str_contains($url, 'user_info') || str_contains($url, '/info?');
                if (!$profile) {
                    parse_str((string)$body, $parameters);
                    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
                    $parameters += $query;
                    $verifier = $parameters['code_verifier'] ?? null;
                    $code = $parameters['code'] ?? null;
                    if (!\is_string($verifier) || !\is_string($code)) {
                        throw new \RuntimeException('The test provider received invalid token parameters.');
                    }

                    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                    if (!hash_equals($this->challenges[$code] ?? '', $challenge)) {
                        throw new \RuntimeException('The test provider rejected an incorrect PKCE verifier.');
                    }
                }

                $result = $profile
                    ? ['id' => 'test-subject', 'user_id' => 'test-subject', 'default_email' => 'reader@example.test', 'first_name' => 'Reader']
                    : ['access_token' => 'test-access-token', 'user_id' => 'test-subject'];

                return new HttpResponse(statusCode: 200, content: json_encode($result, JSON_THROW_ON_ERROR));
            }

            public function resolveRedirectUrl(string $location, string $currentUrl): string
            {
                return (new HttpClient())->resolveRedirectUrl($location, $currentUrl);
            }
        };
        $original = $I->grabService(PublicOAuthClient::class);
        $I->replaceService(PublicOAuthClient::class, new PublicOAuthClient(
            $http, $I->grabService(PublicAuthSettings::class), $I->grabService(PublicAuthRepository::class), $I->grabService(UrlBuilder::class),
        ), [PublicAuthController::class]);

        try {
            $controller = $I->grabService(PublicAuthController::class);
            $token = $I->grabService(PublicAuthFormToken::class)->issue();
            $flows = [];
            $cookies = [];
            foreach (['yandex', 'vk', 'mail_ru', 'ok_ru'] as $provider) {
                $request = Request::create('https://localhost/auth/oauth/' . $provider, 'POST', ['auth_token' => $token], server: ['HTTP_ORIGIN' => 'https://localhost']);
                $request->attributes->add(['auth_action' => 'oauth_start', 'provider' => $provider]);
                $start = $controller->handle($request);
                $I->assertSame(302, $start->getStatusCode());
                parse_str((string)parse_url((string)$start->headers->get('Location'), PHP_URL_QUERY), $query);
                $code = $provider . '-test-code';
                $challenge = $query['code_challenge'] ?? null;
                $I->assertIsString($challenge);
                $http->challenges[$code] = $challenge;
                $callback = 'https://localhost/auth/oauth/' . $provider . '/callback?' . http_build_query([
                    'state' => $query['state'], 'code' => $code, 'device_id' => $query['device_id'] ?? '',
                ]);

                // Another browser must be rejected before any provider request or account creation.
                $foreign = Request::create($callback);
                $foreign->attributes->add(['auth_action' => 'oauth_callback', 'provider' => $provider]);
                $denied = $controller->handle($foreign);
                $I->assertSame(403, $denied->getStatusCode());
                $I->assertSame([], $denied->headers->getCookies());
                $I->assertSame(0, $http->requests);

                $flowCookies = $start->headers->getCookies();
                $I->assertCount(1, $flowCookies);
                $cookie = $flowCookies[0];
                $I->assertTrue($cookie->isSecure());
                $I->assertTrue($cookie->isHttpOnly());
                $I->assertSame('lax', $cookie->getSameSite());
                $I->assertStringStartsWith('__Host-', $cookie->getName());
                $I->assertNull($cookie->getDomain());
                $I->assertSame('/', $cookie->getPath());
                $I->assertGreaterThan(time(), $cookie->getExpiresTime());
                $I->assertLessThanOrEqual(time() + 600, $cookie->getExpiresTime());
                $cookies[$cookie->getName()] = $cookie->getValue();
                $flows[] = ['provider' => $provider, 'callback' => $callback, 'cookie' => $cookie];
            }

            // Each tab keeps its own credential, even when all callbacks share a browser.
            foreach ($flows as $index => $flow) {
                $wrongCookies = $cookies;
                $wrongCookies[$flow['cookie']->getName()] = $flows[($index + 1) % \count($flows)]['cookie']->getValue();
                $wrong = Request::create($flow['callback'], cookies: $wrongCookies);
                $wrong->attributes->add(['auth_action' => 'oauth_callback', 'provider' => $flow['provider']]);
                $I->assertSame(403, $controller->handle($wrong)->getStatusCode());

                $wrongProvider = Request::create($flow['callback'], cookies: $cookies);
                $wrongProvider->attributes->add(['auth_action' => 'oauth_callback', 'provider' => $flow['provider'] === 'yandex' ? 'vk' : 'yandex']);
                $I->assertSame(403, $controller->handle($wrongProvider)->getStatusCode());

                $callback = Request::create($flow['callback'], cookies: $cookies);
                $callback->attributes->add(['auth_action' => 'oauth_callback', 'provider' => $flow['provider']]);
                $response = $controller->handle($callback);
                $I->assertSame(302, $response->getStatusCode());
                $I->assertSame('/', $response->headers->get('Location'));
                $expired = array_values(array_filter($response->headers->getCookies(), static fn(Cookie $cookie): bool => $cookie->getName() === $flow['cookie']->getName()));
                $I->assertCount(1, $expired);
                $I->assertTrue($expired[0]->isCleared());

                $I->assertSame(403, $controller->handle($callback)->getStatusCode());
            }

            $I->assertSame(8, $http->requests);
        } finally {
            $I->replaceService(PublicOAuthClient::class, $original, [PublicAuthController::class]);
        }
    }

    public function testEmailLinkCreatesASeparateUnprivilegedIdentity(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $adminId = $this->userId($dbLayer, 'admin');

        // A public sign-in must also remove a stale control-panel cookie from
        // the same browser instead of silently leaving two identities active.
        $I->amOnPage('https://register.localhost/_admin/index.php');
        $I->sendPost('https://register.localhost/_admin/index.php?action=login', [
            'login' => 'admin',
            'pass'  => 'admin',
        ]);
        $I->assertNotNull($I->grabTestCookie('register_cookie_904732485', '/_admin/'));
        $I->resetTestCookie('register_cookie_904732485_c');

        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabValueFrom('.public-auth-email-form input[name="auth_token"]');
        $I->sendAjaxPostRequest('https://localhost/auth/email', [
            'email'       => 'admin@example.com',
            'name'        => 'Email participant',
            'auth_token'  => $token,
            'return_path' => '/',
        ], headers: ['Origin' => 'https://localhost']);

        $I->seeResponseCodeIs(200);

        $mails = $I->grabPublicAuthMails();
        $I->assertCount(1, $mails);
        $callbackUrl = $this->callbackUrl($mails[0]['message']);
        $rawToken = $this->callbackToken($callbackUrl);
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(PublicAuthSchema::MAGIC_LINKS_TABLE)
            ->where('token_hash = :raw')->setParameter('raw', $rawToken)
            ->execute()
            ->result());

        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(302);
        $I->followRedirect();
        $I->see('Email participant', '.public-auth-user-menu');
        $I->dontSeeElement('.public-auth-menu-item[href*="/_admin/index.php"]');
        $I->assertNotNull($I->grabTestCookie('register_cookie_904732485_c'));
        $I->assertNull($I->grabTestCookie('register_cookie_904732485', '/_admin/'));

        $identity = $dbLayer
            ->select('i.user_id', 'u.login', 'u.edit_users', 'u.create_articles')
            ->from(PublicAuthSchema::IDENTITIES_TABLE . ' AS i')
            ->innerJoin('users AS u', 'u.id = i.user_id')
            ->where("i.provider = 'email'")
            ->andWhere("i.subject = 'admin@example.com'")
            ->execute()
            ->fetchAssoc();
        $I->assertIsArray($identity);
        $I->assertNotSame($adminId, (int)$identity['user_id']);
        $I->assertSame(0, (int)$identity['edit_users']);
        $I->assertSame(0, (int)$identity['create_articles']);
        $I->assertSame(SessionAudience::PUBLIC->value, $dbLayer
            ->select('audience')
            ->from('users_online')
            ->where('login = :login')->setParameter('login', (string)$identity['login'])
            ->execute()
            ->result());

        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(502);
    }

    public function testEmailLinkDeliveryIsRateLimitedWithoutPlainIdentifiers(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $I->amOnPage('https://localhost/');
        $token = (string)$I->grabValueFrom('.public-auth-email-form input[name="auth_token"]');

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $I->sendAjaxPostRequest('https://localhost/auth/email', [
                'email'       => 'limited-reader@example.test',
                'name'        => 'Limited reader',
                'auth_token'  => $token,
                'return_path' => '/',
            ], headers: ['Origin' => 'https://localhost']);
            $I->seeResponseCodeIs(200);
        }

        $I->sendAjaxPostRequest('https://localhost/auth/email', [
            'email'       => 'limited-reader@example.test',
            'name'        => 'Limited reader',
            'auth_token'  => $token,
            'return_path' => '/',
        ], headers: ['Origin' => 'https://localhost']);

        $I->seeResponseCodeIs(429);
        $I->assertGreaterThan(0, (int)$I->grabHttpHeader('Retry-After'));
        $I->assertCount(3, $I->grabPublicAuthMails());

        $rateEvents = $dbLayer
            ->select('bucket_type', 'bucket_key')
            ->from('spam_rate_events')
            ->where("bucket_type LIKE 'auth_mail_%'")
            ->execute()
            ->fetchAssocAll()
        ;
        $I->assertCount(6, $rateEvents);
        $I->assertStringNotContainsString(
            'limited-reader@example.test',
            json_encode($rateEvents, JSON_THROW_ON_ERROR),
        );
        $I->assertStringNotContainsString('127.0.0.1', json_encode($rateEvents, JSON_THROW_ON_ERROR));
    }

    public function testVkStartUsesPkceAndKeepsRawStateOutOfStorage(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var PublicAuthFormToken $formToken */
        $formToken = $I->grabService(PublicAuthFormToken::class);
        $I->setConfigValue(PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY, 'vk-test-client');

        $I->amOnPage('https://localhost/auth/oauth/vk?return=%2F%2Fforeign.example%2Fpath');
        $I->seeResponseCodeIs(405);
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(PublicAuthSchema::FLOWS_TABLE)
            ->execute()
            ->result());

        $I->sendPost('https://localhost/auth/oauth/vk?return=%2F%2Fforeign.example%2Fpath', [
            'auth_token' => $formToken->issue(),
        ], headers: ['Origin' => 'https://localhost']);
        $I->seeResponseCodeIs(302);

        $location = (string)$I->grabHttpHeader('Location');
        $I->assertStringStartsWith('https://id.vk.ru/authorize?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $I->assertSame('vk-test-client', $query['client_id'] ?? null);
        $codeChallengeMethod = $query['code_challenge_method'] ?? null;
        $I->assertIsString($codeChallengeMethod);
        $I->assertSame('S256', strtoupper($codeChallengeMethod));
        $I->assertNotSame('', $query['code_challenge'] ?? '');

        $state = $query['state'] ?? null;
        $I->assertIsString($state);
        $I->assertNotSame('', $state);

        $flow = $dbLayer
            ->select('provider', 'return_path', 'code_verifier', 'device_id')
            ->from(PublicAuthSchema::FLOWS_TABLE)
            ->where('state_hash = :state_hash')
            ->setParameter('state_hash', PublicAuthRepository::tokenHash($state))
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($flow);
        $I->assertSame('vk', $flow['provider']);
        $I->assertSame('/', $flow['return_path']);
        $I->assertNotSame('', $flow['code_verifier']);
        $I->assertNotSame('', $flow['device_id']);
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(PublicAuthSchema::FLOWS_TABLE)
            ->where('state_hash = :raw_state')->setParameter('raw_state', $state)
            ->execute()
            ->result());
    }

    public function testPendingCommentCookieUsesInstallationPathWithQueryBasedUrls(\IntegrationTester $I): void
    {
        // This test needs storage, not delivery; never use a real mailer in the additional applications.
        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '0');
        /** @var \PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $articleId = $this->insertContent($db, 'pending-comment-query-urls');

        foreach (['', '/journal'] as $basePath) {
            $app = $I->createApplication(['base_path' => $basePath, 'url_prefix' => '/index.php?']);
            $app->container->decorate(\PDO::class, static fn(): \PDO => $pdo);
            $response = $app->container->get(MagicLinkService::class)->requestVerification(
                Request::create('https://localhost' . $basePath . '/index.php?/pending-comment-query-urls'),
                new PendingEmailComment(
                    contentType: ContentType::PAGE,
                    targetId: $articleId,
                    name: 'Guest author',
                    email: 'reader@example.test',
                    subscribed: false,
                    text: '<p>The entire pending comment.</p>',
                    ip: '127.0.0.1',
                    parentId: null,
                    returnPath: '/pending-comment-query-urls',
                    moderationRequired: true,
                    spamAssessmentId: null,
                    spamStatus: '',
                    visitorId: null,
                ),
            );
            $cookies = $response->headers->getCookies();
            $I->assertCount(1, $cookies);
            $cookie = $cookies[0];
            $I->assertSame(PendingCommentRecovery::COOKIE_NAME, $cookie->getName());
            $I->assertSame($basePath . '/', $cookie->getPath());
            $I->assertStringStartsWith($basePath . '/index.php?/auth/check-email&draft=', $response->getTargetUrl());
            $recovery = PendingCommentRecovery::fromRequest(Request::create(
                'https://localhost' . $response->getTargetUrl(),
                cookies: [PendingCommentRecovery::COOKIE_NAME => $cookie->getValue()],
            ));
            $I->assertNotNull($recovery);
            $draft = $app->container->get(PublicAuthRepository::class)->pendingComment($recovery->hash());
            $I->assertIsArray($draft);
            $I->assertSame('<p>The entire pending comment.</p>', $draft['comment_text']);
        }
    }

    public function testGuestCanCorrectEmailWithoutLosingOrDuplicatingLongComment(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $articleId = $this->insertContent($db, 'pending-comment-recovery');
        $text = '<p>First paragraph <strong>with formatting</strong>.</p>'
            . '<p>' . str_repeat('Long comment content. ', 200) . '</p>'
            . '<blockquote><p>Last paragraph.</p></blockquote>';
        $stored = CommentHtml::sanitizeForStorage($text);
        $I->sendPost('https://localhost/pending-comment-recovery', [
            'name' => 'Guest author', 'email' => 'reader@examplw.test', 'text' => $text,
        ]);
        $I->seeResponseCodeIs(302);

        $waitingUrl = 'https://localhost' . (string)$I->grabHttpHeader('Location');
        $firstMailUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);
        $I->followRedirect();
        $I->see('Last paragraph.', '.pending-comment-preview');
        $I->seeElement('.pending-comment-preview strong');
        $I->assertSame('reader@examplw.test', $I->grabValueFrom('.pending-comment-email-form [name="email"]'));
        $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));
        $I->assertSame('no-referrer', $I->grabHttpHeader('Referrer-Policy'));
        $I->assertNotNull($I->grabTestCookie(PendingCommentRecovery::COOKIE_NAME));

        $draft = (string)$I->grabValueFrom('.pending-comment-email-form [name="draft"]');
        $token = (string)$I->grabValueFrom('.pending-comment-email-form [name="auth_token"]');

        /** @var CommentFormTokenManager $tokenManager */
        $tokenManager = $I->grabService(CommentFormTokenManager::class);
        $I->sendPostWithAntispamVisitor('https://localhost/auth/check-email', [
            'draft' => $draft, 'auth_token' => $token, 'email' => 'reader@example.test',
            'text' => 'Untrusted replacement must not overwrite the validated comment.',
        ], $tokenManager->getOrCreateVisitorToken(Request::create('https://localhost/')), mutateCommentFields: false);
        $I->assertSame([], $I->grabMultiple('.public-auth-status.is-error'));
        $I->seeResponseCodeIs(303);
        $I->followRedirect();
        $I->see('Last paragraph.', '.pending-comment-preview');
        $I->assertSame('reader@example.test', $I->grabValueFrom('.pending-comment-email-form [name="email"]'));
        $I->assertCount(2, $I->grabPublicAuthMails());
        $I->assertCount(0, $I->grabModeratorMails());
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());

        $I->amOnPage($firstMailUrl);
        $I->seeResponseCodeIs(410);
        $I->see('Last paragraph.', '.pending-comment-preview');
        $I->amOnPage($this->localCallbackUrl($I->grabPublicAuthMails()[1]['message']));
        $I->seeResponseCodeIs(200);
        $I->see('Your comment is confirmed and awaiting moderation.', '[role="status"]');
        $I->assertNotNull($I->grabTestCookie('comment_form_sent'));

        $comment = $db->select('text', 'email', 'shown')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->fetchAssoc();
        $I->assertIsArray($comment);
        $I->assertSame($stored, $comment['text']);
        $I->assertSame('reader@example.test', $comment['email']);
        $I->assertSame(0, (int)$comment['shown']);
        $I->assertCount(2, $I->grabModeratorMails());
        $I->amOnPage($waitingUrl);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment has already been confirmed.', '[role="status"]');
        $I->dontSeeElement('.pending-comment-email-form');
        $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
    }

    public function testExpiredConfirmationAndCleanupRetainThePendingComment(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $repository */
        $repository = $I->grabService(PublicAuthRepository::class);
        $this->insertContent($db, 'expired-pending-comment');
        $I->sendPost('https://localhost/expired-pending-comment', [
            'name' => 'Patient reader', 'email' => 'patient@example.test', 'text' => '<p>Keep this draft after expiry.</p>',
        ]);
        $waitingUrl = 'https://localhost' . (string)$I->grabHttpHeader('Location');
        $callbackUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);
        $db->update(PublicAuthSchema::MAGIC_LINKS_TABLE)->set('expires_at', '1')->where("email = 'patient@example.test'")->execute();
        $repository->storeMagicLink(str_repeat('z', 48), 'login@example.test', 'Login', '/');
        $db->update(PublicAuthSchema::MAGIC_LINKS_TABLE)->set('expires_at', '1')->where("email = 'login@example.test'")->execute();
        $repository->storeFlow('cleanup-trigger', 'vk', '', '', '/');
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(PublicAuthSchema::MAGIC_LINKS_TABLE)->where("email = 'login@example.test'")->execute()->result());
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(410);
        $I->see('Keep this draft after expiry.', '.pending-comment-preview');
        $I->amOnPage($waitingUrl);
        $I->seeResponseCodeIs(200);
        $I->sendPost('https://localhost/auth/check-email', [
            'draft' => $I->grabValueFrom('.pending-comment-email-form [name="draft"]'),
            'auth_token' => $I->grabValueFrom('.pending-comment-email-form [name="auth_token"]'),
            'email' => 'patient@example.test',
        ]);
        $I->seeResponseCodeIs(303);
        $I->amOnPage($this->localCallbackUrl($I->grabPublicAuthMails()[1]['message']));
        $I->seeResponseCodeIs(200);
        $I->assertSame(CommentHtml::sanitizeForStorage('<p>Keep this draft after expiry.</p>'), $db->select('text')->from(CommentSchema::TABLE_NAME)->where("email = 'patient@example.test'")->execute()->result());
    }

    public function testPendingCommentErrorsAndRateLimitNeverHideTheText(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $this->insertContent($db, 'pending-comment-errors');
        $I->sendPost('https://localhost/pending-comment-errors', [
            'name' => 'Careful reader', 'email' => 'careful@example.test', 'text' => '<p>Recoverable draft text.</p>',
        ]);
        $I->followRedirect();

        $draft = (string)$I->grabValueFrom('.pending-comment-email-form [name="draft"]');
        $token = (string)$I->grabValueFrom('.pending-comment-email-form [name="auth_token"]');
        $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => 'forged', 'email' => 'other@example.test']);
        $I->seeResponseCodeIs(403);
        $I->see('Recoverable draft text.', '.pending-comment-preview');
        $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => $token, 'email' => 'not an email']);
        $I->seeResponseCodeIs(422);
        $I->see('Recoverable draft text.', '.pending-comment-preview');
        $I->assertSame('not an email', $I->grabValueFrom('.pending-comment-email-form [name="email"]'));
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => $token, 'email' => 'careful@example.test']);
            $I->seeResponseCodeIs(303);
        }

        $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => $token, 'email' => 'careful@example.test']);
        $I->seeResponseCodeIs(429);
        $I->see('Recoverable draft text.', '.pending-comment-preview');
        $I->assertGreaterThan(0, (int)$I->grabHttpHeader('Retry-After'));
        $I->assertCount(3, $I->grabPublicAuthMails());
        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '0');
        $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => $token, 'email' => 'corrected@example.test']);
        $I->seeResponseCodeIs(502);
        $I->see('Recoverable draft text.', '.pending-comment-preview');
        $I->assertCount(3, $I->grabPublicAuthMails());
    }

    public function testFirstSubmissionRetainsTheCommentWhenMailIsUnavailableOrRateLimited(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var MagicLinkRateLimiter $rateLimiter */
        $rateLimiter = $I->grabService(MagicLinkRateLimiter::class);
        $this->insertContent($db, 'pending-comment-delivery-errors');
        for ($attempt = 0; $attempt < MagicLinkRateLimiter::EMAIL_LIMIT; ++$attempt) {
            $rateLimiter->consume('127.0.0.1', 'limited@example.test');
        }

        $I->sendPost('https://localhost/pending-comment-delivery-errors', [
            'name' => 'Limited reader', 'email' => 'limited@example.test', 'text' => '<p>Keep the rate-limited comment.</p>',
        ]);
        $I->seeResponseCodeIs(302);
        $I->assertStringContainsString('delivery=limited', (string)$I->grabHttpHeader('Location'));
        $I->followRedirect();
        $I->see('Keep the rate-limited comment.', '.pending-comment-preview');
        $I->seeElement('.public-auth-status.is-error');
        $I->assertNotNull($I->grabTestCookie(PendingCommentRecovery::COOKIE_NAME));
        $I->assertCount(0, $I->grabPublicAuthMails());
        $I->sendPost('https://localhost/auth/check-email', [
            'draft' => $I->grabValueFrom('.pending-comment-email-form [name="draft"]'),
            'auth_token' => $I->grabValueFrom('.pending-comment-email-form [name="auth_token"]'),
            'email' => 'corrected@example.test',
        ]);
        $I->seeResponseCodeIs(303);
        $I->followRedirect();
        $I->see('Keep the rate-limited comment.', '.pending-comment-preview');
        $I->assertCount(1, $I->grabPublicAuthMails());

        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '0');
        $I->sendPost('https://localhost/pending-comment-delivery-errors', [
            'name' => 'Patient reader', 'email' => 'unavailable@example.test', 'text' => '<p>Keep the undelivered comment.</p>',
        ]);
        $I->seeResponseCodeIs(302);
        $I->assertStringContainsString('delivery=failed', (string)$I->grabHttpHeader('Location'));
        $I->followRedirect();
        $I->see('Keep the undelivered comment.', '.pending-comment-preview');
        $I->seeElement('.public-auth-status.is-error');
        $I->assertCount(1, $I->grabPublicAuthMails());
    }

    public function testPendingCommentCannotBeReadOrResentFromAnotherBrowser(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $this->insertContent($db, 'private-pending-comment');
        $I->sendPost('https://localhost/private-pending-comment', [
            'name' => 'Private reader', 'email' => 'private@example.test', 'text' => '<p>Private draft contents.</p>',
        ]);
        $waitingUrl = (string)$I->grabHttpHeader('Location');
        $I->followRedirect();
        $draft = (string)$I->grabValueFrom('.pending-comment-email-form [name="draft"]');
        $token = (string)$I->grabValueFrom('.pending-comment-email-form [name="auth_token"]');
        $I->resetTestCookie(PendingCommentRecovery::COOKIE_NAME);
        $I->amOnPage($waitingUrl);
        $I->seeResponseCodeIs(404);
        $I->dontSee('Private draft contents.');
        $I->dontSee('private@example.test');
        $I->sendPost('https://localhost/auth/check-email', ['draft' => $draft, 'auth_token' => $token, 'email' => 'attacker@example.test']);
        $I->seeResponseCodeIs(404);
        $I->assertCount(1, $I->grabPublicAuthMails());
        $I->assertSame('private@example.test', $db->select('email')->from(PublicAuthSchema::MAGIC_LINKS_TABLE)->where('content_id IS NOT NULL')->execute()->result());
    }

    public function testConfirmedCommentShowsModerationAndCanBeReopenedWithoutSigningInAgain(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $articleId = $this->insertContent($db, 'comment-confirmation-status');
        $I->resetTestCookie('register_cookie_904732485_c');
        $I->sendPost('https://localhost/comment-confirmation-status', [
            'name' => 'Confirmation reader',
            'email' => 'confirmation-reader@example.test',
            'text' => '<p>A private comment awaiting moderation.</p>',
        ]);
        $callbackUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment is confirmed and awaiting moderation.', '[role="status"]');
        $I->see('Return to the article', 'a[href="/comment-confirmation-status"]');
        $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));
        $I->assertSame('no-referrer', $I->grabHttpHeader('Referrer-Policy'));
        $I->assertSame('noindex, nofollow', $I->grabHttpHeader('X-Robots-Tag'));
        $I->assertNotNull($I->grabTestCookie('register_cookie_904732485_c'));
        $I->assertNotNull($I->grabTestCookie('comment_form_sent'));

        $comment = $db->select('id', 'shown')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->fetchAssoc();
        $I->assertIsArray($comment);
        $I->assertSame(0, (int)$comment['shown']);

        $sessions = (int)$db->select('COUNT(*)')->from('users_online')->execute()->result();
        $moderatorMails = $I->grabModeratorMails();
        $I->assertNotEmpty($moderatorMails);

        // Opening the same link in its original browser is an acknowledgment, not another login.
        $sessionCookie = $I->grabTestCookie('register_cookie_904732485_c');
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment has already been confirmed.', '[role="status"]');
        $I->dontSee('This saved comment is not available in this browser.');
        $I->assertSame($sessionCookie, $I->grabTestCookie('register_cookie_904732485_c'));

        // A used email token can show this status in a different browser even after expiry,
        // but it must never create a new authenticated session or expose the saved text/email.
        $I->resetTestCookie(PendingCommentRecovery::COOKIE_NAME);
        $I->resetTestCookie('register_cookie_904732485_c');
        $I->resetTestCookie('comment_form_sent');
        $db->update(PublicAuthSchema::MAGIC_LINKS_TABLE)->set('expires_at', ':expired')->setParameter('expired', time() - 1)->execute();
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment has already been confirmed.', '[role="status"]');
        $I->see('Return to the article', 'a[href="/comment-confirmation-status"]');
        $I->dontSee('A private comment awaiting moderation.');
        $I->dontSee('confirmation-reader@example.test');
        $I->dontSeeElement('.pending-comment-email-form');
        $I->assertNull($I->grabTestCookie('register_cookie_904732485_c'));
        $I->assertNull($I->grabTestCookie('comment_form_sent'));
        $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));
        $I->assertSame($sessions, (int)$db->select('COUNT(*)')->from('users_online')->execute()->result());
        $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
        $I->assertSame(0, (int)$db->select('shown')->from(CommentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', (int)$comment['id'])->execute()->result());
        $I->assertSame($moderatorMails, $I->grabModeratorMails());
        $I->assertCount(1, $I->grabPublicAuthMails());
        $I->assertCount(0, $I->grabSubscriberMails());
    }

    public function testClaimedLinkWithoutASavedCommentIsNotReportedAsConfirmed(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $repository */
        $repository = $I->grabService(PublicAuthRepository::class);
        $articleId = $this->insertContent($db, 'interrupted-comment-confirmation');
        $I->resetTestCookie('register_cookie_904732485_c');
        $I->sendPost('https://localhost/interrupted-comment-confirmation', [
            'name' => 'Interrupted reader', 'email' => 'interrupted@example.test', 'text' => '<p>Not saved yet.</p>',
        ]);
        $callbackUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);

        // A legacy interrupted confirmation may have a claim without a saved comment.
        // used_at alone cannot be treated as evidence of a successful confirmation.
        $I->assertNotNull($repository->consumeMagicLink($this->callbackToken($callbackUrl)));
        $I->amOnPage($callbackUrl);
        $I->dontSee('Your comment has already been confirmed.');
        $I->dontSee('Your comment is confirmed and awaiting moderation.');
        $I->assertNull($I->grabTestCookie('register_cookie_904732485_c'));
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
    }

    public function testFailedConfirmationRollsBackTheCommentAndCanRetryTheSameLink(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $repository = $I->grabService(PublicAuthRepository::class);
        $articleId = $this->insertContent($db, 'retry-comment-confirmation');
        $I->sendPost('https://localhost/retry-comment-confirmation', [
            'name' => 'Retry reader', 'email' => 'retry-reader@example.test', 'text' => '<p>Keep this draft after a failed save.</p>',
        ]);
        $waitingUrl = (string)$I->grabHttpHeader('Location');
        $callbackUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);
        $token = $this->callbackToken($callbackUrl);
        $dependencies = [MagicLinkService::class, PublicAuthController::class];
        $probe = new ConfirmationFailureDbLayer($I->grabService(\PDO::class), $db);
        $I->replaceService(PublicAuthRepository::class, new PublicAuthRepository($probe), $dependencies);
        try {
            $service = $I->grabService(MagicLinkService::class);
            $I->expectThrowable(new \RuntimeException('Temporary confirmation write failure'), fn(): array => $service->consume($token));
            $I->assertTrue($probe->failedAfterCommentSave);
            $I->assertTrue($I->grabService(\PDO::class)->inTransaction(), 'Keep the caller transaction open.');
            $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
                ->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
            $I->assertSame(0, (int)$db->select('COUNT(*)')->from('users')
                ->where('email = :email')->setParameter('email', 'retry-reader@example.test')->execute()->result());
            $row = $db->select('used_at, confirmed_comment_id')->from(PublicAuthSchema::MAGIC_LINKS_TABLE)
                ->where('token_hash = :hash')->setParameter('hash', PublicAuthRepository::tokenHash($token))->execute()->fetchAssoc();
            $I->assertIsArray($row);
            $I->assertNull($row['used_at']);
            $I->assertNull($row['confirmed_comment_id']);
            $I->amOnPage('https://localhost' . $waitingUrl);
            $I->seeResponseCodeIs(200);
            $I->see('Keep this draft after a failed save.', '.pending-comment-preview');
            $I->amOnPage($callbackUrl);
            $I->seeResponseCodeIs(200);
            $I->see('Your comment is confirmed and awaiting moderation.', '[role="status"]');
            $I->amOnPage($callbackUrl);
            $I->see('Your comment has already been confirmed.', '[role="status"]');
            $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
                ->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
        } finally {
            $I->replaceService(PublicAuthRepository::class, $repository, $dependencies);
        }
    }

    public function testPublishedEmailCommentKeepsItsRedirectAndIsAcknowledgedOnReplay(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $repository */
        $repository = $I->grabService(PublicAuthRepository::class);
        $articleId = $this->insertContent($db, 'published-comment-confirmation');
        $I->resetTestCookie('register_cookie_904732485_c');
        $userId = $repository->findOrCreateIdentity('email', 'established@example.test', 'established@example.test', 'Established reader');
        $this->insertComment($db, $articleId, 'Established reader', 'established@example.test', $userId);
        $I->sendPost('https://localhost/published-comment-confirmation', [
            'name' => 'Established reader', 'email' => 'established@example.test', 'text' => '<p>A newly published comment.</p>',
        ]);
        $callbackUrl = $this->localCallbackUrl($I->grabPublicAuthMails()[0]['message']);
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(302);

        $commentId = (int)$db->select('id')->from(CommentSchema::TABLE_NAME)->where("text LIKE '%A newly published comment.%'")->execute()->result();
        $I->assertGreaterThan(0, $commentId);
        $I->seeLocationIs('/published-comment-confirmation#comment-' . $commentId);
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment has already been confirmed.', '[role="status"]');
        $I->dontSee('Your comment is confirmed and awaiting moderation.');
        $I->assertSame(2, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->result());
    }

    public function testGuestFirstCommentIsVerifiedButHeldUntilModeration(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $articleId = $this->insertContent($dbLayer, 'email-comment-test');

        $I->amOnPage('https://localhost/email-comment-test');
        $I->see('After you confirm your email, your first comment will be reviewed before publication.', '.comment-public-auth');
        $I->seeElement('#comment-form .comment-submit[value="Send confirmation link"]');
        $I->dontSeeElement('#comment-form .comment-email-submit');

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $resolved = $I->grabJson();
        $I->assertIsArray($resolved);
        $visitorId = $identityManager->visitorIdFromToken((string)($resolved['token'] ?? ''));
        $I->assertNotNull($visitorId);

        $I->sendPost('https://localhost/email-comment-test', [
            'name'        => 'Verified reader',
            'email'       => 'verified-reader@example.test',
            'text'        => '<p>A comment waiting for its link.</p>',
        ]);
        $I->seeResponseCodeIs(302);
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where("email = 'verified-reader@example.test'")
            ->execute()
            ->result());

        $mails = $I->grabPublicAuthMails();
        $I->assertCount(1, $mails);
        $I->resetTestCookie($identityManager->cookieName());
        $I->amOnPage($this->callbackUrl($mails[0]['message']));
        $I->seeResponseCodeIs(200);
        $I->see('Your comment is confirmed and awaiting moderation.', '[role="status"]');

        $comment = $dbLayer
            ->select('id', 'content_id', 'user_id', 'visitor_id', 'shown', 'text')
            ->from(CommentSchema::TABLE_NAME)
            ->where("email = 'verified-reader@example.test'")
            ->execute()
            ->fetchAssoc();
        $I->assertIsArray($comment);
        $I->assertSame($articleId, (int)$comment['content_id']);
        $I->assertGreaterThan(0, (int)$comment['user_id']);
        $I->assertSame($visitorId, $comment['visitor_id']);
        $I->assertSame(0, (int)$comment['shown']);
        $I->assertStringContainsString('A comment waiting for its link.', (string)$comment['text']);
        $I->assertSame(1, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(VisitorIdentityManifest::USER_LINK_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', (int)$comment['user_id'])
            ->execute()
            ->result());
        $I->see('Return to the article', 'a[href="/email-comment-test"]');

        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markHam((int)$comment['id'], ContentType::PAGE));
        $I->assertSame(1, (int)$dbLayer
            ->select('shown')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', (int)$comment['id'])
            ->execute()
            ->result());

        // The approved first comment establishes publication history for this
        // external identity. Its next clean comment can be published normally.
        $I->amOnPage('http://register.localhost/email-comment-test');
        $I->sendPost('http://register.localhost/email-comment-test', [
            'text' => '<p>A second, established-reader comment.</p>',
        ]);
        $I->seeResponseCodeIs(302);
        $I->assertSame(1, (int)$dbLayer
            ->select('shown')
            ->from(CommentSchema::TABLE_NAME)
            ->where("text LIKE '%established-reader comment%'")
            ->execute()
            ->result());
    }

    public function testVerifiedSpamStaysHiddenAndUnsentUntilItIsApproved(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $authRepository */
        $authRepository = $I->grabService(PublicAuthRepository::class);
        $articleId = $this->insertContent($dbLayer, 'verified-spam-test');
        $subscriberUserId = $authRepository->findOrCreateIdentity(
            'email',
            'subscriber@example.test',
            'subscriber@example.test',
            'Subscribed reader',
        );
        $this->insertComment(
            $dbLayer,
            $articleId,
            'Subscribed reader',
            'subscriber@example.test',
            $subscriberUserId,
            subscribed: true,
        );
        $this->insertComment(
            $dbLayer,
            $articleId,
            'Legacy unverified subscriber',
            'unverified-subscriber@example.test',
            subscribed: true,
        );
        $replyUserId = $authRepository->findOrCreateIdentity(
            'email',
            'reply-recipient@example.test',
            'reply-recipient@example.test',
            'Reply recipient',
        );
        $parentId = $this->insertComment(
            $dbLayer,
            $articleId,
            'Reply recipient',
            'reply-recipient@example.test',
            $replyUserId,
        );
        $I->setSpamResponses([SpamDetectorReport::STATUS_SPAM]);

        $I->sendPost('https://localhost/verified-spam-test', [
            'name'      => 'Suspicious reader',
            'email'     => 'suspicious-reader@example.test',
            'text'      => '<p>A suspicious pending comment.</p>',
            'parent_id' => (string)$parentId,
        ]);
        $I->seeResponseCodeIs(302);
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where("email = 'suspicious-reader@example.test'")
            ->execute()
            ->result());
        $I->assertCount(0, $I->grabSubscriberMails());
        $I->assertCount(0, $I->grabModeratorMails());

        $authMails = $I->grabPublicAuthMails();
        $I->assertCount(1, $authMails);
        $I->amOnPage($this->callbackUrl($authMails[0]['message']));
        $I->seeResponseCodeIs(200);
        $I->see('Your comment is confirmed and awaiting moderation.', '[role="status"]');

        $comment = $dbLayer
            ->select('id', 'shown', 'sent')
            ->from(CommentSchema::TABLE_NAME)
            ->where("email = 'suspicious-reader@example.test'")
            ->execute()
            ->fetchAssoc();
        $I->assertIsArray($comment);
        $I->assertSame(0, (int)$comment['shown']);
        $I->assertSame(0, (int)$comment['sent']);
        $I->assertCount(0, $I->grabSubscriberMails());

        $moderatorMails = $I->grabModeratorMails();
        $I->assertNotEmpty($moderatorMails);
        foreach ($moderatorMails as $moderatorMail) {
            $I->assertSame(SpamDetectorReport::STATUS_SPAM, $moderatorMail['spamReportStatus']);
            $I->assertFalse($moderatorMail['isPublished']);
        }

        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markHam((int)$comment['id'], ContentType::PAGE));
        $I->assertSame(['shown' => 1, 'sent' => 1], $dbLayer
            ->select('shown', 'sent')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', (int)$comment['id'])
            ->execute()
            ->fetchAssoc());
        $subscriberMails = $I->grabSubscriberMails();
        $I->assertCount(2, $subscriberMails);
        $mailsByEmail = array_column($subscriberMails, null, 'subscriberEmail');
        $I->assertArrayHasKey('subscriber@example.test', $mailsByEmail);
        $I->assertArrayHasKey('reply-recipient@example.test', $mailsByEmail);
        $I->assertArrayNotHasKey('unverified-subscriber@example.test', $mailsByEmail);
        $I->assertNull($mailsByEmail['reply-recipient@example.test']['unsubscribeLink']);
    }

    public function testRelevantUnreadCommentsAreCountedAndMarkedPerContent(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $authRepository */
        $authRepository = $I->grabService(PublicAuthRepository::class);
        /** @var CommentNotificationRepository $notifications */
        $notifications = $I->grabService(CommentNotificationRepository::class);

        $userId = $this->userId($dbLayer, 'admin');
        $user = new AuthenticatedPublicUser(
            $userId,
            'admin',
            'admin@example.com',
            'Admin',
            true,
            true,
            true,
            true,
            true,
            str_repeat('a', 64),
        );
        $this->markExistingPendingCommentsHandled($dbLayer);
        $authRepository->ensureNotificationBaseline($userId);

        $ownedId = $this->insertContent($dbLayer, 'owned-notifications', $userId);
        $subscribedId = $this->insertContent($dbLayer, 'subscribed-notifications');
        $unsubscribedId = $this->insertContent($dbLayer, 'unsubscribed-notifications');
        $replyId = $this->insertContent($dbLayer, 'reply-notifications');
        $unrelatedId = $this->insertContent($dbLayer, 'unrelated-notifications');

        $ownedComment = $this->insertComment($dbLayer, $ownedId, 'Owned reader', 'owned@example.test');
        $this->insertComment($dbLayer, $subscribedId, 'Before subscription', 'before@example.test');
        $this->insertComment($dbLayer, $subscribedId, 'Admin', $user->email, $userId, subscribed: true);
        $subscribedComment = $this->insertComment(
            $dbLayer,
            $subscribedId,
            'Subscribed discussion',
            'subscribed@example.test',
        );
        $this->insertComment($dbLayer, $unsubscribedId, 'Admin', $user->email, $userId);
        $this->insertComment(
            $dbLayer,
            $unsubscribedId,
            'Unsubscribed discussion',
            'unsubscribed@example.test',
        );
        $parentId = $this->insertComment($dbLayer, $replyId, 'Admin', $user->email, $userId);
        $replyComment = $this->insertComment(
            $dbLayer,
            $replyId,
            'Direct reply',
            'direct@example.test',
            parentId: $parentId,
        );
        $this->insertComment($dbLayer, $unrelatedId, 'Unrelated', 'unrelated@example.test');
        $pendingComment = $this->insertComment(
            $dbLayer,
            $unrelatedId,
            'Pending moderation',
            'pending@example.test',
            shown: false,
            sent: false,
        );
        $this->insertComment(
            $dbLayer,
            $unrelatedId,
            'Handled spam',
            'spam@example.test',
            shown: false,
            sent: true,
        );
        $pendingState = $dbLayer
            ->select('shown, sent, deleted')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $pendingComment)
            ->execute()
            ->fetchAssoc();
        $I->assertSame(['shown' => 0, 'sent' => 0, 'deleted' => 0], $pendingState);

        $I->assertSame(4, $notifications->countUnread($user));
        /** @var \Register\Core\Pdo\PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $pdo->cleanLogs();

        $I->assertSame($pendingComment, $notifications->firstUnread($user)?->commentId);
        $I->assertSame([], $pdo->getQueryLog(), 'The count and first notification must share one snapshot.');

        $I->assertSame(3, $notifications->countUnread(new AuthenticatedPublicUser(
            $user->id,
            $user->login,
            $user->email,
            $user->name,
            false,
            false,
            false,
            false,
            false,
            $user->sessionHash,
        )));
        $I->assertSame($pendingComment, $notifications->firstUnread($user)->commentId);

        $notifications->markContentRead($user, ContentId::page($unrelatedId));
        $I->assertSame(4, $notifications->countUnread($user));
        $I->assertSame($pendingComment, $notifications->firstUnread($user)?->commentId);

        $dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('sent', '1')
            ->where('id = :id')->setParameter('id', $pendingComment)
            ->execute()
        ;
        // Direct test writes deliberately bypass CommentRepository and its CommentChangedEvent.
        $notifications->invalidateAll();
        $I->assertSame(3, $notifications->countUnread($user));
        $I->assertSame($ownedComment, $notifications->firstUnread($user)?->commentId);

        $notifications->markContentRead($user, ContentId::page($ownedId));
        $I->assertSame(2, $notifications->countUnread($user));
        $I->assertSame($subscribedComment, $notifications->firstUnread($user)?->commentId);

        $notifications->markContentRead($user, ContentId::page($subscribedId));
        $I->assertSame(1, $notifications->countUnread($user));
        $I->assertSame($replyComment, $notifications->firstUnread($user)?->commentId);

        $notifications->markContentRead($user, ContentId::page($replyId));
        $I->assertSame(0, $notifications->countUnread($user));
    }

    public function testPendingModerationPredatingNotificationBaselineStillRequiresAction(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $authRepository */
        $authRepository = $I->grabService(PublicAuthRepository::class);
        /** @var CommentNotificationRepository $notifications */
        $notifications = $I->grabService(CommentNotificationRepository::class);

        $userId = $this->userId($dbLayer, 'admin');
        $user = new AuthenticatedPublicUser(
            $userId,
            'admin',
            'admin@example.com',
            'Admin',
            true,
            true,
            true,
            true,
            true,
            str_repeat('a', 64),
        );
        $this->markExistingPendingCommentsHandled($dbLayer);
        $contentId = $this->insertContent($dbLayer, 'pending-before-notifications');
        $pendingComment = $this->insertComment(
            $dbLayer,
            $contentId,
            'Pending before baseline',
            'pending-before@example.test',
            shown: false,
            sent: false,
        );

        $authRepository->ensureNotificationBaseline($userId);
        $dbLayer
            ->update(PublicAuthSchema::NOTIFICATION_USERS_TABLE)
            ->set('initial_comment_id', ':comment_id')->setParameter('comment_id', $pendingComment)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;

        $I->assertSame(1, $notifications->countUnread($user));
        $I->assertSame($pendingComment, $notifications->firstUnread($user)?->commentId);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');
        $I->seeElement('.public-auth-unread[data-unread-comments-count="1"][href="/auth/unread"]');
        $I->sendRequestWithMethod('GET', 'https://localhost/auth/unread');
        $I->seeResponseCodeIs(302);
        $I->assertSame(
            '/pending-before-notifications?comment_unread=' . $pendingComment . '#comment-' . $pendingComment,
            $I->grabHttpHeader('Location'),
        );

        $notifications->markContentRead($user, ContentId::page($contentId));
        $I->assertSame(1, $notifications->countUnread($user));
        $I->assertSame($pendingComment, $notifications->firstUnread($user)?->commentId);

        $dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('sent', '1')
            ->where('id = :id')->setParameter('id', $pendingComment)
            ->execute()
        ;
        // Direct test writes deliberately bypass CommentRepository and its CommentChangedEvent.
        $notifications->invalidateAll();
        $I->assertSame(0, $notifications->countUnread($user));
    }

    public function testUnreadMarkerReadsTheVisibleThreadAndKeepsReadStatePerUser(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var CommentNotificationRepository $notifications */
        $notifications = $I->grabService(CommentNotificationRepository::class);
        /** @var PublicAuthRepository $auth */
        $auth = $I->grabService(PublicAuthRepository::class);
        $this->markExistingPendingCommentsHandled($db);
        $adminId = $this->userId($db, 'admin');
        $otherId = $this->userId($db, 'guest');
        $admin = new AuthenticatedPublicUser($adminId, 'admin', 'admin@example.com', 'Admin', true, true, true, true, true, str_repeat('a', 64));
        $other = new AuthenticatedPublicUser($otherId, 'guest', 'guest@example.com', 'Guest', true, true, false, false, false, str_repeat('b', 64));
        $auth->ensureNotificationBaseline($adminId);
        $auth->ensureNotificationBaseline($otherId);

        $contentId = $this->insertContent($db, 'sequential-comments', $adminId);
        $pending = $this->insertComment($db, $contentId, 'Pending', 'pending@example.test', shown: false, sent: false);
        $first = $this->insertComment($db, $contentId, 'First', 'first@example.test');
        $second = $this->insertComment($db, $contentId, 'Second', 'second@example.test');
        $otherContentId = $this->insertContent($db, 'other-sequential-comments', $adminId);
        $otherComment = $this->insertComment($db, $otherContentId, 'Other thread', 'other-thread@example.test');
        $notifications->invalidateAll();
        $I->assertSame(4, $notifications->countUnread($admin));
        $I->assertSame(1, $notifications->countUnread($other));

        $notifications->markThreadReadFromComment($admin, ContentId::page(1), $pending);
        $I->assertSame(4, $notifications->countUnread($admin), 'A comment on another content item cannot mark a thread read.');

        $I->login('admin', 'admin');
        $I->sendRequestWithMethod('GET', 'https://localhost/auth/unread');
        $I->seeResponseCodeIs(302);

        $location = '/sequential-comments?comment_unread=' . $pending . '#comment-' . $pending;
        $I->assertSame($location, $I->grabHttpHeader('Location'));
        $I->amOnPage('https://localhost' . $location);
        $I->seeResponseCodeIs(200);
        $I->see('Pending');
        $I->see('First');
        $I->see('Second');
        $I->seeElement('.public-auth-unread[data-unread-comments-count="1"]');
        $I->assertSame(1, $notifications->countUnread($admin));
        $I->assertSame($otherComment, $notifications->firstUnread($admin)?->commentId);
        $I->assertSame(1, $notifications->countUnread($other), 'Reading is local to the authenticated user.');

        $I->sendRequestWithMethod('GET', 'https://localhost/auth/unread');
        $I->assertSame(
            '/other-sequential-comments?comment_unread=' . $otherComment . '#comment-' . $otherComment,
            $I->grabHttpHeader('Location'),
        );

        $pendingState = $db->select('shown, sent')->from(CommentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $pending)->execute()->fetchAssoc();
        $I->assertIsArray($pendingState);
        $I->assertSame(['shown' => 0, 'sent' => 0], array_map(intval(...), $pendingState));
    }

    private function markExistingPendingCommentsHandled(DbLayer $dbLayer): void
    {
        $dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('sent', '1')
            ->where('shown = 0 AND sent = 0')
            ->execute()
        ;
    }

    private function callbackUrl(string $message): string
    {
        if (preg_match('~https?://[^\s]+/auth/email/callback\?token=[A-Za-z0-9_-]+(?:&draft=[a-f0-9]{32})?~', $message, $matches) !== 1) {
            throw new \RuntimeException('The test email contains no callback URL.');
        }

        return $matches[0];
    }

    private function callbackToken(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException('The callback URL contains no token.');
        }

        return $token;
    }

    private function localCallbackUrl(string $message): string
    {
        // Match the request origin, so the browser-owned Secure cookie is actually sent.
        return 'https://localhost/auth/email/callback?' . (string)parse_url($this->callbackUrl($message), PHP_URL_QUERY);
    }

    private function userId(DbLayer $dbLayer, string $login): int
    {
        return (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->result();
    }

    private function insertContent(DbLayer $dbLayer, string $slug, ?int $authorId = null): int
    {
        $now = time();
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':type')->setParameter('type', ContentType::PAGE->value)
            ->setValue('parent_id', '1')
            ->setValue('author_id', ':author_id')->setParameter('author_id', $authorId)
            ->setValue('slug_scope', "'root'")
            ->setValue('title', ':title')->setParameter('title', $slug)
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Page text</p>'")
            ->setValue('created_at', ':now')->setParameter('now', $now)
            ->setValue('published_at', ':now')
            ->setValue('updated_at', ':now')
            ->setValue('revision', '1')
            ->setValue('sort_order', '0')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('slug', ':slug')->setParameter('slug', $slug)
            ->setValue('template', "'site.php'")
            ->setValue('id', (string)ContentIdAllocator::allocate($dbLayer))
            ->execute();

        return (int)$dbLayer->insertId();
    }

    private function insertComment(
        DbLayer $dbLayer,
        int $contentId,
        string $name,
        string $email,
        ?int $userId = null,
        ?int $parentId = null,
        bool $subscribed = false,
        bool $shown = true,
        bool $sent = true,
    ): int {
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':type')->setParameter('type', ContentType::PAGE->value)
            ->setValue('content_id', ':content_id')->setParameter('content_id', $contentId)
            ->setValue('parent_id', ':parent_id')->setParameter('parent_id', $parentId)
            ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
            ->setValue('userpic_id', 'NULL')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'127.0.0.1'")
            ->setValue('nick', ':nick')->setParameter('nick', $name)
            ->setValue('email', ':email')->setParameter('email', $email)
            ->setValue('subscribed', $subscribed ? '1' : '0')
            ->setValue('shown', $shown ? '1' : '0')
            ->setValue('sent', $sent ? '1' : '0')
            ->setValue('good', '0')
            ->setValue('text', "'<p>Visible comment</p>'")
            ->execute();

        return (int)$dbLayer->insertId();
    }
}

/** Injects a storage failure after saving the comment but before writing its confirmation receipt. */
final class ConfirmationFailureDbLayer extends DbLayer
{
    public bool $failedAfterCommentSave = false;

    public function __construct(\PDO $pdo, private readonly DbLayer $delegate)
    {
        parent::__construct($pdo, $delegate->getPrefix());
    }

    #[\Override]
    public function insert(string $table): InsertBuilder
    {
        return $this->delegate->insert($table);
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, int> $types
     */
    #[\Override]
    public function query(string $sql, array $params = [], array $types = []): QueryResult
    {
        if (!$this->failedAfterCommentSave && str_starts_with($sql, 'UPDATE ') && isset($params['comment_id'], $params['token_hash'])) {
            $this->failedAfterCommentSave = (int)parent::query(
                'SELECT COUNT(*) FROM ' . $this->getPrefix() . CommentSchema::TABLE_NAME . ' WHERE id = :id',
                ['id' => $params['comment_id']],
            )->result() === 1;
            throw new \RuntimeException('Temporary confirmation write failure');
        }

        return parent::query($sql, $params, $types);
    }
}
