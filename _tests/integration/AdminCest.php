<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentType;
use S2\Cms\Admin\AdminConfigProvider;
use S2\Cms\Comment\Antispam\SpamAssessment;
use S2\Cms\Comment\Antispam\SpamAssessmentRepository;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\LoginRateLimiter;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group admin
 */
class AdminCest
{
    public function testLogin(\IntegrationTester $I): void
    {
        $I->amOnPage('https://localhost/_admin/index.php');
        $I->see('Shared computer');
        $I->seeElement('input[name="foreign_computer"]:not([checked])');

        $I->login('admin', 'no-pass');
        $I->seeResponseCodeIs(401);

        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);
    }

    public function testRegisterAdminShellAndListDensity(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');

        $I->seeElement('link[rel="stylesheet"][href="/_admin/css/register.css"]');
        $I->seeElement('header.admin-shell');
        $I->seeElement('a.admin-brand[href="/"]');
        $I->see('Register', 'a.admin-brand');
        $I->seeElement('nav[aria-label="Control panel navigation"]');
        $I->seeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=list"]');
        $I->see('Log out', 'a.main-menu-logout-link');
        $I->dontSee('Log out?');

        /** @var AdminConfigProvider $adminConfigProvider */
        $adminConfigProvider = $I->grabAdminService(AdminConfigProvider::class);
        $adminConfig = $adminConfigProvider->getAdminConfig();
        foreach (['Article', 'BlogPost', 'Comment', 'Queue'] as $entityName) {
            $entity = $adminConfig->findEntityByName($entityName)
                ?? throw new \LogicException($entityName . ' admin entity is missing.');
            $I->assertSame(50, $entity->getLimit());
        }

        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->see('Overview', 'h1#dashboard-title');
        $I->seeElement('[data-analytics-table="register-analytics-pages"]');
        $I->seeElement('[data-analytics-table="register-analytics-feeds"]');
        $I->see('Environment', '.environment-stat-item h3');
        $I->see('PHP:', '.environment-stat-item');
        $I->see('Database', '.environment-stat-item');
        $I->dontSee('Database', '.stat-item > h3');
        $I->see('Register source code', 'a[href="https://github.com/bolknote/Register"]');
        $I->see('Register is based on', '.stat-item .technical-data');
        $I->see('S2 2.0dev', 'a[href="https://github.com/parpalak/s2"]');
        $I->dontSee('© 2007–');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Queue&action=list');
        $I->see('Queue', 'h1');
        $I->see('Job identifier', 'table.list-table th');
        $I->see('Failed at', 'table.list-table th');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list');
        $I->see('Content', 'table.list-table th');
        $I->dontSee('Email', 'table.list-table th');
        $I->dontSee('Content type', 'table.list-table th');
        $I->dontSee('Spam label', 'table.list-table th');

        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=list');
        $I->see('Roles', 'table.list-table th');
        $I->dontSee('Password', 'table.list-table th');

        $editUserHref = $I->grabAttributeFrom('a.list-action-link-edit', 'href');
        $I->assertNotNull($editUserHref);
        $I->amOnPage('https://localhost/_admin/index.php' . $editUserHref);
        $I->see('Edit user', 'h1');
        $I->assertSame('', $I->grabValueFrom('input[name="password"]'));

        $I->amOnPage('https://localhost/_admin/index.php?entity=Site');
        $I->see('Page structure', 'h1');
        $I->seeElement('button#create_page_button');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemModules');
        $I->see('System modules', 'h1');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Extension');
        $I->see('Optional modules', 'h1');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Config&action=list');
        $I->dontSee('Output gzip compression');
    }

    public function testNewPostUsesEditorialEditor(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=new');

        $I->see('New post', 'h1');
        $I->seeElement('section.post-edit-content.is-new');
        $I->seeElement('form[name="article-form"][action="?entity=BlogPost&action=new"]');
        $I->seeElement('script[type="module"][src="/_admin/js/editor/entry.js"]');
        $I->seeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=new"]');
        $I->dontSeeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=list"]');
        $I->see('Create draft', '.article-form-buttons button[type="submit"]');
        $I->see('Preview and publishing become available after the draft is created.', '.content-editor-note');

        $I->submitForm('form[name="article-form"]', [
            'title'      => 'Editorial editor draft',
            'tags'       => 'register, admin',
            'date_label' => '',
            'body'       => '<p>Created through the shared editor.</p>',
        ]);

        $I->seeResponseCodeIs(302);

        $location = $I->grabHttpHeader('Location');
        $I->assertNotNull($location);
        $I->assertStringContainsString('?entity=BlogPost&action=edit&id=', $location);
        $I->amOnPage('https://localhost/_admin/index.php' . $location);
        $I->assertSame('Editorial editor draft', $I->grabValueFrom('input[name="title"]'));
        $I->assertSame('register, admin, ', $I->grabValueFrom('input[name="tags"]'));
        $I->seeElement('section.post-edit-content.is-edit');
    }

    public function testEditingUserKeepsBlankPasswordAndUpdatesRoles(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);

        $statement = $pdo->prepare('SELECT id, password, name, email, create_articles FROM users WHERE login = :login');
        $I->assertNotFalse($statement);
        $statement->execute(['login' => 'admin']);
        $admin = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $I->assertIsArray($admin);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=edit&id=' . $admin['id']);
        $I->submitForm('form', [
            'login'           => 'admin',
            'password'        => '',
            'name'            => $admin['name'],
            'email'           => $admin['email'],
            'view'            => true,
            'view_hidden'     => true,
            'hide_comments'   => true,
            'edit_comments'   => true,
            'create_articles' => false,
            'edit_site'       => true,
            'edit_users'      => true,
        ]);

        $I->seeResponseCodeIs(302);

        $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id');
        $I->assertNotFalse($passwordStatement);
        $passwordStatement->execute(['id' => $admin['id']]);
        $password = $passwordStatement->fetchColumn();
        $passwordStatement->closeCursor();
        $I->assertSame($admin['password'], $password);

        $roleStatement = $pdo->prepare('SELECT create_articles FROM users WHERE id = :id');
        $I->assertNotFalse($roleStatement);
        $roleStatement->execute(['id' => $admin['id']]);
        $createArticles = $roleStatement->fetchColumn();
        $roleStatement->closeCursor();
        $I->assertSame(0, (int)$createArticles);
    }

    public function testNewUserStillRequiresPassword(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=new');
        $I->submitForm('form', [
            'login'    => 'passwordless-user',
            'password' => '',
            'name'     => '',
            'email'    => 'passwordless@example.com',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('This value should not be blank.', '.error-message-box');

        $countStatement = $pdo->query("SELECT COUNT(*) FROM users WHERE login = 'passwordless-user'");
        $I->assertNotFalse($countStatement);
        $userCount = $countStatement->fetchColumn();
        $countStatement->closeCursor();
        $I->assertSame(0, (int)$userCount);
    }

    public function testFailedLoginsAreRateLimitedWithoutRawIdentifiers(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $clientIp = '198.51.100.41';

        for ($attempt = 0; $attempt < LoginRateLimiter::FAILURE_LIMIT; ++$attempt) {
            $response = $this->loginResponse($authManager, 'missing-user', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $blocked = $this->loginResponse($authManager, 'missing-user', 'wrong-password', $clientIp);
        $I->assertSame(429, $blocked->getStatusCode());
        $retryAfter = $blocked->headers->get('Retry-After');
        $I->assertNotNull($retryAfter);
        $I->assertGreaterThan(0, (int)$retryAfter);
        $I->assertLessThanOrEqual(LoginRateLimiter::WINDOW_SECONDS + 1, (int)$retryAfter);

        $rows = $dbLayer
            ->select('bucket_type, bucket_key')
            ->from('spam_rate_events')
            ->where("bucket_type IN ('auth_ip', 'auth_login')")
            ->execute()
            ->fetchAssocAll()
        ;
        $I->assertCount(2 * LoginRateLimiter::FAILURE_LIMIT, $rows);
        foreach ($rows as $row) {
            $I->assertContains($row['bucket_type'], ['auth_ip', 'auth_login']);
            $I->assertSame(64, \strlen((string)$row['bucket_key']));
            $I->assertStringNotContainsString($clientIp, (string)$row['bucket_key']);
            $I->assertStringNotContainsString('missing-user', (string)$row['bucket_key']);
        }
    }

    public function testSuccessfulLoginClearsPreviousFailures(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        $clientIp = '198.51.100.42';

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $success = $this->loginResponse($authManager, 'admin', 'admin', $clientIp);
        $I->assertSame(200, $success->getStatusCode());

        for ($attempt = 0; $attempt < LoginRateLimiter::FAILURE_LIMIT; ++$attempt) {
            $response = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $blocked = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
        $I->assertSame(429, $blocked->getStatusCode());
    }

    public function testBlogPreviewLoadsTemplateFromBaseModule(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_template&template_id=blog.php&article_id=2&content_type=post');
        $I->seeResponseCodeIs(200);

        $response = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $I->assertIsArray($response);
        $I->assertTrue($response['success'] ?? false);
        $I->assertStringContainsString('<!-- s2_text -->', (string)($response['template'] ?? ''));
    }

    public function testLoginLifetimeAndSharedComputerMode(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);

        $persistentResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => 'admin', 'pass' => 'admin'],
            server: ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'Persistent browser'],
        ));
        $I->assertNotNull($persistentResponse);
        $persistentCookies = $persistentResponse->headers->getCookies();
        $I->assertCount(2, $persistentCookies);
        foreach ($persistentCookies as $cookie) {
            $I->assertGreaterThan(time() + 4 * 365 * 86400, $cookie->getExpiresTime());
        }

        $adminCookie = array_values(array_filter(
            $persistentCookies,
            static fn(\Symfony\Component\HttpFoundation\Cookie $cookie): bool => !str_ends_with($cookie->getName(), '_c'),
        ))[0];
        $I->assertStringStartsWith('p', (string)$adminCookie->getValue());
        $dbLayer
            ->update('users_online')
            ->set('time', ':time')->setParameter('time', time() - 86400)
            ->set('ip', ':ip')->setParameter('ip', '192.0.2.1')
            ->where('challenge = :challenge')->setParameter('challenge', $adminCookie->getValue())
            ->execute()
        ;

        $authenticatedResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php',
            'GET',
            cookies: [$adminCookie->getName() => $adminCookie->getValue()],
            server: ['REMOTE_ADDR' => '192.0.2.2', 'HTTP_USER_AGENT' => 'Persistent browser'],
        ));
        $I->assertNull($authenticatedResponse);

        $renewedResponse = new \Symfony\Component\HttpFoundation\Response();
        $authManager->renewPersistentCookies(Request::create(
            'https://localhost/_admin/index.php',
            cookies: [$adminCookie->getName() => $adminCookie->getValue()],
        ), $renewedResponse);
        $I->assertCount(2, $renewedResponse->headers->getCookies());

        $foreignComputerResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => 'admin', 'pass' => 'admin', 'foreign_computer' => '1'],
            server: ['REMOTE_ADDR' => '192.0.2.3', 'HTTP_USER_AGENT' => 'Shared browser'],
        ));
        $I->assertNotNull($foreignComputerResponse);
        $foreignComputerCookies = $foreignComputerResponse->headers->getCookies();
        $I->assertCount(2, $foreignComputerCookies);
        foreach ($foreignComputerCookies as $cookie) {
            $I->assertSame(0, $cookie->getExpiresTime());
        }

        $foreignAdminCookie = array_values(array_filter(
            $foreignComputerCookies,
            static fn(\Symfony\Component\HttpFoundation\Cookie $cookie): bool => !str_ends_with($cookie->getName(), '_c'),
        ))[0];
        $I->assertStringStartsWith('t', (string)$foreignAdminCookie->getValue());
        $notRenewedResponse = new \Symfony\Component\HttpFoundation\Response();
        $authManager->renewPersistentCookies(Request::create(
            'https://localhost/_admin/index.php',
            cookies: [$foreignAdminCookie->getName() => $foreignAdminCookie->getValue()],
        ), $notRenewedResponse);
        $I->assertCount(0, $notRenewedResponse->headers->getCookies());
    }

    public function testSecureCookiePolicy(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $authManager, 'http://localhost/_admin/index.php', true);

        $httpApplication = $I->createAdminApplication([
            'force_admin_https' => false,
            'base_url'          => 'http://s2.localhost',
        ]);
        /** @var AuthManager $httpAuthManager */
        $httpAuthManager = $httpApplication->container->get(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $httpAuthManager, 'http://localhost/_admin/index.php', false);
        $this->assertSecureCookiePolicy($I, $httpAuthManager, 'https://localhost/_admin/index.php', true);

        $httpsBaseUrlApplication = $I->createAdminApplication([
            'force_admin_https' => false,
            'base_url'          => 'https://s2.localhost',
        ]);
        /** @var AuthManager $httpsBaseUrlAuthManager */
        $httpsBaseUrlAuthManager = $httpsBaseUrlApplication->container->get(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $httpsBaseUrlAuthManager, 'http://localhost/_admin/index.php', true);
    }

    public function testNobody(\IntegrationTester $I): void
    {
        $I->login('nobody', 'nobody');
        $I->seeResponseCodeIs(200);
        $I->amOnPage('https://localhost/_admin/index.php');
        $I->see('Access denied');
        $I->see('You do not have permission to access this page.');
    }

    public function testGuest(\IntegrationTester $I): void
    {
        $I->login('guest', 'no-pass');
        $I->seeResponseCodeIs(401);

        $I->login('guest', 'guest');
        $I->seeResponseCodeIs(200);
    }

    public function testAntispamCalibrationPages(\IntegrationTester $I): void
    {
        $assessment = new SpamAssessment(
            50,
            ['links' => 50],
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
        );
        /** @var SpamAssessmentRepository $assessmentRepository */
        $assessmentRepository = $I->grabAdminService(SpamAssessmentRepository::class);
        $assessmentRepository->save(
            $assessment,
            SpamDetectorReport::STATUS_SPAM,
            contentType: ContentType::PAGE,
            commentId: 1,
        );
        $assessmentRepository->labelComment(1, 'ham', $assessment, ContentType::PAGE);

        $I->login('admin', 'admin');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamAssessment&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Antispam report');
        $I->see('Local filter quality');
        $I->see('Shadow comparison');
        $I->see('False positive');
        $I->see('Links');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamAssessment&action=list&quality=false_positive&apply_filter=1');
        $I->seeResponseCodeIs(200);
        $I->see('False positive');
        $I->see('Links');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamSignalPolicy&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Spam signal weights');
        $I->see('One link');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamSignalPolicy&action=edit&signal=links_one');
        $I->seeResponseCodeIs(200);
        $I->see('Edit spam signal weight');
        $I->see('Score adjustment');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamRatePolicy&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Comment rate limits');
        $I->see('IP address');
        $I->see('Allowed attempts');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamRatePolicy&action=edit&bucket_type=ip');
        $I->seeResponseCodeIs(200);
        $I->see('Edit comment rate limit');
        $I->see('Window, seconds');
    }

    private function assertSecureCookiePolicy(\IntegrationTester $I, AuthManager $authManager, string $url, bool $expected): void
    {
        $method = new \ReflectionMethod(AuthManager::class, 'shouldUseSecureCookies');

        $I->assertSame($expected, $method->invoke($authManager, Request::create($url)));
    }

    private function loginResponse(AuthManager $authManager, string $login, string $password, string $clientIp): \Symfony\Component\HttpFoundation\Response
    {
        $response = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => $login, 'pass' => $password],
            server: ['REMOTE_ADDR' => $clientIp, 'HTTP_USER_AGENT' => 'Rate limiter test'],
        ));

        return $response ?? throw new \RuntimeException('A login attempt must return a response.');
    }
}
