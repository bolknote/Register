<?php

declare(strict_types = 1);

namespace integration;

use Register\Auth\PublicSessionManager;
use Register\Content\ContentSchema;
use Register\Core\Model\AuthManager;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\AuthTokenHasher;
use Register\Core\Model\SessionToken;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

final class PublicSessionLifetimeCest
{
    private const string COOKIE = 'register_cookie_904732485';

    public function expiredPublicSessionCannotCreateContent(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $db = $I->grabService(DbLayer::class);
        $count = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
        $db->update('users_online')->set('time', ':time')->setParameter('time', time() - 8 * 86400)->execute();

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create', 'inplace_token' => $token,
            'title' => 'Expired editor', 'body' => '<p>Must not be saved.</p>',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertSame($count, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
        $I->amOnPage('https://localhost/');
        $I->dontSeeElement('.post-create-template');
    }

    public function publicAndAdminAuthenticationEnforceTheSameLimits(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $provider = $I->grabService(AuthProvider::class);
        $manager = $I->grabAdminService(AuthManager::class);
        $now = time();
        foreach ([
            [true, 0, 0, true],
            [true, 0, 1801, false],
            [true, 12 * 3600 + 1, 0, false],
            [false, 0, 0, true],
            [false, 0, 7 * 86400 + 1, false],
            [false, 30 * 86400 + 1, 0, false],
        ] as [$temporary, $age, $idle, $active]) {
            $adminToken = SessionToken::create($temporary, $now - $age);
            $publicToken = SessionToken::create($temporary, $now - $age);
            $db->insert('users_online')->values([
                'login' => "'admin'", 'challenge' => ':challenge', 'comment_cookie' => ':cookie',
                'time' => ':time', 'audience' => "'admin'",
            ])->execute([
                'challenge' => AuthTokenHasher::session($adminToken),
                'cookie' => AuthTokenHasher::comment($publicToken), 'time' => $now - $idle,
            ]);
            $publicRequest = Request::create('https://localhost/', cookies: [self::COOKIE . '_c' => $publicToken]);
            $I->assertSame($active, $provider->getAuthenticatedContentEditor($publicRequest) !== null);
            $adminRequest = Request::create('https://localhost/_admin/index.php',
                cookies: [self::COOKIE => $adminToken], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $I->assertSame($active, $manager->checkAuth($adminRequest) === null);
        }
    }

    public function publicPasswordSessionsRefreshActivityAndRejectAlteredOrLegacyTokens(\IntegrationTester $I): void
    {
        $manager = $I->grabService(PublicSessionManager::class);
        $provider = $I->grabService(AuthProvider::class);
        $db = $I->grabService(DbLayer::class);
        foreach ([false, true] as $remember) {
            $response = $manager->loginWithPassword(Request::create('https://localhost/auth/password', 'POST', [
                'login' => 'admin', 'pass' => 'admin', 'remember_me' => $remember ? '1' : '0',
            ]));
            $cookies = [];
            foreach ($response->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = (string)$cookie->getValue();
            }

            $publicToken = $cookies[self::COOKIE . '_c'];
            $I->assertNotSame($cookies[self::COOKIE], $publicToken);
            $I->assertStringStartsWith($remember ? 'p' : 't', $publicToken);
            $hash = AuthTokenHasher::comment($publicToken);
            $db->update('users_online')->set('time', ':time')->setParameter('time', time() - 60)
                ->where('comment_cookie = :cookie')->setParameter('cookie', $hash)->execute();
            $request = Request::create('https://localhost/', cookies: [self::COOKIE . '_c' => $publicToken]);
            $I->assertNotNull($provider->getAuthenticatedContentEditor($request));
            $I->assertGreaterThanOrEqual(time() - 1, (int)$db->select('time')->from('users_online')
                ->where('comment_cookie = :cookie')->setParameter('cookie', $hash)->execute()->result());
            $altered = ($remember ? 't' : 'p') . substr($publicToken, 1);
            $I->assertNull($provider->getAuthenticatedContentEditor(Request::create('https://localhost/',
                cookies: [self::COOKIE . '_c' => $altered])));

            $legacy = bin2hex(random_bytes(32));
            $db->update('users_online')->set('comment_cookie', ':legacy')->setParameter('legacy', AuthTokenHasher::comment($legacy))
                ->where('comment_cookie = :cookie')->setParameter('cookie', $hash)->execute();
            $I->assertNull($provider->getAuthenticatedContentEditor(Request::create('https://localhost/',
                cookies: [self::COOKIE . '_c' => $legacy])));
        }
    }
}
