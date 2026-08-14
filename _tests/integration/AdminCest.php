<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentType;
use S2\Cms\Comment\Antispam\SpamAssessment;
use S2\Cms\Comment\Antispam\SpamAssessmentRepository;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Model\AuthManager;
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
}
