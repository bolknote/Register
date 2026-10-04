<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Auth\CommentNotificationRepository;
use Register\Auth\MagicLinkRateLimiter;
use Register\Auth\PublicAuthFormToken;
use Register\Auth\PublicAuthRepository;
use Register\Auth\PublicAuthSchema;
use Register\Auth\PublicAuthSettings;
use Register\Auth\PendingCommentRecovery;
use Register\Core\Comment\CommentHtml;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Register\Comment\CommentSchema;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\SessionAudience;
use Register\Core\Comment\SpamDetectorReport;
use Register\Core\Pdo\DbLayer;
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
        ]);

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
            ]);
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
        ]);

        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals(false, ['success']);
        $I->assertNull($I->grabTestCookie('register_cookie_904732485_c'));
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
        ]);

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
            ]);
            $I->seeResponseCodeIs(200);
        }

        $I->sendAjaxPostRequest('https://localhost/auth/email', [
            'email'       => 'limited-reader@example.test',
            'name'        => 'Limited reader',
            'auth_token'  => $token,
            'return_path' => '/',
        ]);

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
        ]);
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
        $I->seeResponseCodeIs(302);
        $I->assertNotNull($I->grabTestCookie('comment_form_sent'));

        $comment = $db->select('text', 'email', 'shown')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $articleId)->execute()->fetchAssoc();
        $I->assertIsArray($comment);
        $I->assertSame($stored, $comment['text']);
        $I->assertSame('reader@example.test', $comment['email']);
        $I->assertSame(0, (int)$comment['shown']);
        $I->assertCount(2, $I->grabModeratorMails());
        $I->amOnPage($waitingUrl);
        $I->seeResponseCodeIs(404);
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
        $I->seeResponseCodeIs(302);
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
        $I->seeResponseCodeIs(302);

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
        $I->seeLocationIs('/email-comment-test');

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
        $I->seeResponseCodeIs(302);

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
