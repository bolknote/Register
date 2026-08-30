<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace acceptance;

use AcceptanceTester;
use Codeception\Example;
use Register\Auth\PublicAuthSettings;
use Register\Core\Http\ContentSecurityPolicy;
use Register\Core\Mail\MailSettings;

/**
 * @group install
 */
class InstallCest
{
    private const string URL_PREFIX = '/index.php?';

    private int $blogPostId;

    /**
     * @return array<int, array<string, string>>
     */
    protected function configProvider(): array
    {
        return [
            ['db_type' => 'mysql', 'db_user' => 'root', 'db_password' => ''],
            ['db_type' => 'sqlite', 'db_user' => '', 'db_password' => ''],
            ['db_type' => 'pgsql', 'db_user' => 'postgres', 'db_password' => '12345'],
        ];
    }

    /**
     * @throws \JsonException
     */
    public function runTest(AcceptanceTester $I): void
    {
        $dbType = getenv('APP_DB_TYPE');
        foreach ($this->configProvider() as $config) {
            if (!\is_string($dbType) || $dbType === $config['db_type']) {
                $file = __DIR__ . '/../../config.test.php';
                if (file_exists($file)) {
                    unlink($file);
                }

                $this->tryToTest($I, new Example($config));
            }
        }
    }

    private function getCookieName(): string
    {
        static $cookieName = null;
        if ($cookieName === null) {
            $config = include __DIR__ . '/../../config.test.php';
            if (!\is_array($config) || !isset($config['cookies']['name'])) {
                throw new \RuntimeException('Unable to determine cookie name from config.test.php');
            }

            $cookieName = (string)$config['cookies']['name'];
        }

        return $cookieName;
    }

    /**
     * @throws \JsonException
     */
    protected function tryToTest(AcceptanceTester $I, Example $example): void
    {
        if (file_exists('config.test.php')) {
            throw new \Exception('config.test.php must not exist for test run');
        }

        $this->testInstallerHonorsMaintenanceMode($I);
        $I->install('admin', 'register-test-password', $example['db_type'], $example['db_user'], $example['db_password']);

        $installedConfig = include __DIR__ . '/../../config.test.php';
        $I->assertIsArray($installedConfig);
        $I->assertSame(
            '_tests/_output/config.acceptance.secrets.php',
            $installedConfig['security']['secret_file'] ?? null,
        );
        $backupEncryptionKey = $installedConfig['backups']['encryption_key'] ?? null;
        $I->assertIsString($backupEncryptionKey);
        $I->assertGreaterThanOrEqual(64, \strlen($backupEncryptionKey));

        $I->amOnPage('/');
        $I->see('Register');
        $I->see('A place for posts');
        $I->see('Register is a blog engine, not a universal site builder');
        $I->seeElement('meta[name="Generator"][content="Register"]');
        $I->seeElement('link[href$="/_styles/register/favicon.svg"]');
        $I->seeElement('script[src$="/_assets/register/syntax-highlighting/loader.js"]');
        $I->seeElement('script[src$="/_assets/register/audio-player/loader.js"]');
        $I->dontSeeElement('a.visual-login');
        $I->dontSeeElement('.public-auth-email-form');
        $this->assertCsp($I);
        $I->dontSeeElement('script:not([src])');
        $I->dontSeeElement('[onclick], [onload], [onsubmit], [onchange]');
        $I->amOnPage('/?search=1&q=personal+blog');
        $I->see('A place for posts');
        $I->see('small, fast engine');
        $I->amOnPage('/section1/page1');
        $I->see('Register was installed successfully.');

        $this->testHierarchyRedirects($I);
        $this->testAdminLogin($I);
        $this->testBaseModules($I);
        $this->testEmailVerifiedGuestComment($I);
        $this->testAdminEditAndTagsAdded($I);
        $this->testTagsPage($I);
        $this->testFavoritePage($I);
        $this->testBlogModule($I);
        $this->testBlogRssAndSitemap($I);
        $this->testSearchModule($I);
        $this->testAdminAddArticles($I);
        $this->testRssAndSitemap($I);
        $this->testAdminTagListAndEdit($I);
        $this->testAdminCommentManagement($I);
        $this->testETag($I);
    }

    private function testInstallerHonorsMaintenanceMode(AcceptanceTester $I): void
    {
        $marker = dirname(__DIR__, 2) . '/.register-maintenance.json';
        $content = "{\"release_id\":\"acceptance-test\"}\n";
        if (file_put_contents($marker, $content, LOCK_EX) !== strlen($content)) {
            throw new \RuntimeException('Unable to create the maintenance-mode acceptance fixture.');
        }

        try {
            $I->amOnPage('/_admin/install.php');
            $I->seeResponseCodeIs(503);
            $I->see('Register is being updated');
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    private function assertCsp(AcceptanceTester $I): void
    {
        $headers = array_change_key_case($I->grabHeaders(), CASE_LOWER);

        $I->assertSame([ContentSecurityPolicy::POLICY], $headers['content-security-policy'] ?? []);
        $reportUri = self::URL_PREFIX . ContentSecurityPolicy::REPORT_PATH;
        $I->assertSame([
            ContentSecurityPolicy::REPORT_ONLY_POLICY
                . '; report-uri ' . $reportUri . '; report-to register-csp',
        ], $headers['content-security-policy-report-only'] ?? []);
        $I->assertSame(['register-csp="' . $reportUri . '"'], $headers['reporting-endpoints'] ?? []);
        $I->assertSame(['nosniff'], $headers['x-content-type-options'] ?? []);
        $I->assertSame(['strict-origin-when-cross-origin'], $headers['referrer-policy'] ?? []);
        $I->assertSame(['camera=(), microphone=(), geolocation=()'], $headers['permissions-policy'] ?? []);
    }

    private function testHierarchyRedirects(AcceptanceTester $I): void
    {
        $I->stopFollowingRedirects();
        $I->amOnPage('/section1');
        $I->seeResponseCodeIs(301);
        $I->followRedirect();
        $I->seeCurrentUrlEquals(self::URL_PREFIX . '/section1/');
        $I->amOnPage('/section1/page1/');
        $I->seeResponseCodeIs(301);
        $I->followRedirect();
        $I->seeCurrentUrlEquals(self::URL_PREFIX . '/section1/page1');
        $I->startFollowingRedirects();
    }

    private function testAdminLogin(AcceptanceTester $I): void
    {
        $I->login('admin', 'no-pass');
        $I->seeResponseCodeIs(401);
        $I->see('You have entered incorrect username or password.');

        $I->login('admin', 'register-test-password');
        $I->seeResponseCodeIs(200);
        $I->dontSee('You have entered incorrect username or password.');

        $I->amOnPage('/---');
        $I->see('admin', 'details[data-menu-group="Account"] .main-menu-group-label');
    }

    private function testEmailVerifiedGuestComment(AcceptanceTester $I): void
    {
        $I->changeSetting(MailSettings::FROM_EMAIL_CONFIG_KEY, 'webmaster@example.com');
        $I->changeSetting(MailSettings::ENVELOPE_EMAIL_CONFIG_KEY, 'webmaster@example.com');
        $I->changeSetting(MailSettings::REPLY_TO_CONFIG_KEY, 'webmaster@example.com');
        $I->changeSetting(MailSettings::FROM_NAME_CONFIG_KEY, 'Webmaster Name');
        $I->changeSetting(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, true);

        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');
        $I->amOnPage('/section1/page1');
        $I->seeElement('.public-auth-email-form');
        $I->canWriteComment(premoderation: true);
        $this->restoreAdminSession($I);
        $this->approveHiddenComment($I, '/section1/page1', 'This is my first comment! 👪🐶');
    }

    private function restoreAdminSession(AcceptanceTester $I): void
    {
        $I->resetCookie($this->getCookieName(), ['path' => '/_admin/']);
        $I->resetCookie($this->getCookieName() . '_c', ['path' => '/']);
        $I->login('admin', 'register-test-password');
    }

    private function approveHiddenComment(AcceptanceTester $I, string $path, string $text): void
    {
        $I->amOnPage($path);
        $I->see($text, '.comment-item.is-hidden');
        $I->submitForm('.comment-item.is-hidden form[data-moderation-action="show"]', []);
        $I->seeResponseCodeIs(200);
        $I->see($text, '.comment-item:not(.is-hidden)');
        $I->clearEmails();
    }

    private function testBaseModules(AcceptanceTester $I): void
    {
        $I->amOnPage('/_admin/index.php?entity=SystemModules');
        $I->see('System modules', 'h1');

        foreach (['register_blog', 'register_search', 'register_latex', 'register_visitor_identity', 'register_counter', 'register_reactions', 'register_typo', 'register_syntax_highlighting', 'register_audio_player'] as $moduleId) {
            $I->seeElement('.base-module [title=' . $moduleId . ']');
            $I->dontSeeElement('.extension.available [title=' . $moduleId . ']');
            $I->dontSeeElement('.extension:not(.base-module) [title=' . $moduleId . ']');
        }

        $I->dontSeeElement('.base-module button');
        $I->seeElement('link[href$="/_assets/register/blog/admin.css"]');
        $I->dontSeeElement('link[href*="/_extensions/register_blog/"]');
        $I->seeElement('link[href$="/_assets/register/math/math.css"]');
        $I->seeElement('script[src$="/_assets/register/math/loader.js"]');
        $I->dontSeeElement('script[src*="/_extensions/register_latex/"]');

        $I->amOnPage('/_admin/index.php?entity=Extension');
        $I->dontSee('Built-in modules', 'h2');
        $I->see('Optional modules available for install or upgrade', 'h2');

        $I->amOnPage('/_admin/index.php?entity=Dashboard');
        $I->see('Overview', 'h1');
        $I->see('Needs attention', 'h3');
        $I->dontSeeElement('a[href="https://github.com/bolknote/Register"]');

        $I->amOnPage('/_admin/index.php?entity=Statistics');
        $I->see('Analytics', 'h1');
        $I->seeElement('script[src$="/_assets/register/analytics/highstock.js"]');
        $I->seeElement('script[src$="/_assets/register/analytics/charts.js"]');
        $I->dontSeeElement('script[src*="/_extensions/register_counter/"]');

        $I->amOnPage('/_admin/index.php?entity=SystemStatus');
        $I->see('System status', 'h1');
        $I->seeElement('script[src$="/_assets/register/search/index-manager.js"]');
        $I->dontSeeElement('script[src*="/_extensions/register_search/"]');
        $I->seeElement('input[name=register_search_csrf_token]');

        $I->amOnPage('/_admin/index.php?entity=Configuration');
        $I->dontSee('REGISTER_ANALYTICS_SALT');
        $I->dontSee('REGISTER_VISITOR_SECRET');
    }

    /**
     * @throws \JsonException
     */
    private function testAdminEditAndTagsAdded(AcceptanceTester $I): void
    {
        $I->amOnPage('/tags/tag1');
        $I->seeResponseCodeIsClientError();
        $I->amOnPage('/tags/another tag');
        $I->seeResponseCodeIsClientError();
        $I->amOnPage('/_admin/index.php?entity=Tag&action=list');
        $I->dontSee('another tag');

        $I->amOnPage('/_admin/index.php?entity=Article&action=edit&id=3');
        $I->assertStringContainsString('Register was installed successfully.', $I->grabValueFrom('textarea[name=body]'));

        $dataProvider = (static fn(string $csrfToken): array => [
            '__csrf_token' => $csrfToken,
            'title'        => 'New Page Title',
            'meta_keywords' => 'New Meta Keywords',
            'meta_description' => 'New Meta Description',
            'excerpt'      => 'New Excerpt',
            'tags'         => 'tag1, another tag',
            'published_at' => '2023-08-10T11:32',
            'updated_at'   => '2023-08-11T12:15',
            'body'         => '<p>Some new page text</p>',
            'revision'     => '1',
            'author_id'    => '1',
            'template'     => 'site.php',
            'slug'         => 'new-page1',
            'featured'     => '1',
            'published'    => '1',
            'comments_enabled' => '1',
        ]);
        $csrfToken    = $I->grabValueFrom('input[name=__csrf_token]');
        $I->sendAjaxPostRequest('/_admin/index.php?entity=Article&action=edit&id=333', $dataProvider($csrfToken));
        $this->assertJsonResponseContains($I, ['errors', 0], 'Unable to confirm security token.');

        for ($i = 0; $i < 2; ++$i) {
            // 2-nd iteration checks that consequent saving of the same entity works fine
            $I->sendAjaxPostRequest('/_admin/index.php?entity=Article&action=edit&id=3', $dataProvider($csrfToken));
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('Warning! An error occurred during page saving. Copy the content to a text editor and save into a file out of caution.');
            $I->see('"success":true');
            $I->see('"urlStatus":"ok"');
            $I->see('"urlTitle":""');
            $I->see('"revision":"2"');
        }

        $I->sendAjaxGetRequest('/_admin/ajax.php?action=load_tree');
        $I->see('{"success":false,"message":"Parameter \u0022id\u0022 is required."}');
        $I->sendAjaxGetRequest('/_admin/ajax.php?action=load_tree&id=0&search=');
        $I->assertStringContainsString('New Page Title', $I->grabPageSource());

        $I->amOnPage('/section1/page1');
        $I->seeResponseCodeIsClientError();

        $I->amOnPage('/section1/new-page1');
        $I->see('Some new page text');
        $I->see('August 10, 2023');

        $I->amOnPage('/section1/');
        $I->see('New Excerpt');

        $I->amOnPage('/tags/tag1');
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage('/tags/another tag');
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage('/_admin/index.php?entity=Tag&action=list');
        $I->see('another tag');
    }

    private function testAdminAddArticles(AcceptanceTester $I): void
    {
        $parentCsrfToken = $this->getArticleCsrfToken($I, 2);

        $createdIds = [];
        foreach ([4, 5] as $pageNumber) {
            $I->sendAjaxPostRequest('/_admin/ajax.php?action=create&id=2', [
                'title'      => 'New page ' . $pageNumber,
                'csrf_token' => $parentCsrfToken,
            ]);
            $data = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
            $I->assertArrayHasKey('success', $data);
            $I->assertTrue($data['success']);
            $I->assertArrayHasKey('id', $data);
            $newId = (int)$data['id'];
            $I->assertGreaterThan(3, $newId);
            $I->assertNotContains($newId, $createdIds);
            $createdIds[] = $newId;

            $I->amOnPage('/_admin/index.php?entity=Article&action=edit&id=' . $newId);
            $csrfToken = $I->grabValueFrom('input[name=__csrf_token]');
            $I->assertSame('new-page-' . $pageNumber, $I->grabValueFrom('input[name=slug]'));

            $dataProvider = static fn(string $id, string $csrfToken): array => [
                '__csrf_token' => $csrfToken,
                'title'        => 'New Page ' . $id,
                'meta_keywords' => 'New Meta Keywords',
                'meta_description' => 'New Meta Description',
                'excerpt'      => 'New Excerpt',
                'tags'         => 'tag1, another tag',
                'published_at' => '2023-08-10T11:32',
                'updated_at'   => '2023-08-12T12:15',
                'body'         => '<p>Some new page text</p>',
                'revision'     => '1',
                'author_id'    => '1',
                'template'     => 'site.php',
                'slug'         => 'new-page' . $id,
                'featured'     => '1',
                'published'    => '1',
                'comments_enabled' => '1',
            ];

            $I->sendAjaxPostRequest('/_admin/index.php?entity=Article&action=edit&id=' . $newId, $dataProvider((string)$pageNumber, $csrfToken));
            $I->seeResponseCodeIsSuccessful();
            $I->see('"success":true');
            $I->see('"urlStatus":"ok"');
            $I->see('"urlTitle":""');
            $I->see('"revision":"2"');
        }

        // Links to related pages in section and by tags
        $I->amOnPage('/section1/new-page4');
        $I->see('New Page 4', 'h1');
        $I->see('Some new page text', '#content');

        $I->see('More in the section “Section 1”', '.header.menu_siblings');
        $I->see('New Page Title', '.menu_siblings a');
        $I->see('New Page 4', '.menu_siblings span');

        $I->see('On the subject “tag1”', '.header.article_tags');
        $I->see('New Page Title', '.article_tags a');
        $I->see('New Page 4', '.article_tags span');

        $I->see('See in blog', '.header.register_blog_tags');
        $I->see('tag1', '.register_blog_tags a');

        // Links to sub-pages
        $I->amOnPage('/section1/');
        $I->see('In this section', '.header.menu_children');
        $I->see('New Page Title', '.menu_children a');
        $I->see('New Page 4', '.menu_children a');

        $I->see('New Page Title', 'h3.subsection');
        $I->see('New Excerpt', 'p.subsection');
    }

    private function testTagsPage(AcceptanceTester $I): void
    {
        $I->stopFollowingRedirects();
        $I->amOnPage('/tags');
        $I->seeResponseCodeIs(301);
        $I->startFollowingRedirects();
        $I->amOnPage('/tags/');
        $I->see('tag1');
        $I->see('another tag');
    }

    private function testFavoritePage(AcceptanceTester $I): void
    {
        $I->stopFollowingRedirects();
        $I->amOnPage('/favorite');
        $I->seeResponseCodeIs(301);
        $I->startFollowingRedirects();
        $I->amOnPage('/favorite/');
        $I->see('New Excerpt');
    }

    private function testRssAndSitemap(AcceptanceTester $I): void
    {
        $I->stopFollowingRedirects();
        $I->amOnPage('/index.php?/rss.xml'); // Historical feed address remains valid for existing subscriptions.
        $I->seeResponseCodeIs(301);
        $I->assertSame(['/index.php?/rss'], $I->grabHeaders()['Location'] ?? []);
        $I->startFollowingRedirects();

        $I->amOnPage('/index.php?/rss');
        $I->seeResponseCodeIsSuccessful();

        $rssHeaders = $I->grabHeaders();
        $I->assertSame(['application/rss+xml; charset=utf-8'], $rssHeaders['Content-Type'] ?? []);
        $I->assertStringContainsString('public', $rssHeaders['Cache-Control'][0] ?? '');
        $I->assertArrayNotHasKey('Pragma', $rssHeaders);
        $I->canSee('Register');
        $I->canSee('My blog');
        $I->canSee('New Blog Post Title');
        $I->canSee('/new-post1');
        $I->canSee(gmdate('D, d M Y H:i:s', strtotime('2023-08-12 11:32:00')) . ' GMT');
        $I->see('New blog post');
        $I->dontSee('New Page Title');

        $etag = $I->grabHeaders()['ETag'][0]
            ?? throw new \RuntimeException('The blog RSS response has no ETag header.');
        $I->haveHttpHeader('If-None-Match', $etag);
        $I->amOnPage('/index.php?/rss');
        $I->seeResponseCodeIs(304);
        $I->dontSee('New Blog Post Title');
        $I->unsetHttpHeader('If-None-Match');

        $I->amOnPage('/index.php?/sitemap.xml'); // Same as above
        $I->seeResponseCodeIsSuccessful();
        $I->see('/index.php?/sitemap-1.xml');

        $I->amOnPage('/index.php?/sitemap-1.xml');
        $I->seeResponseCodeIsSuccessful();
        $I->see('/section1/new-page1');
        $I->see('/new-post1');
        $I->see(gmdate('c', strtotime('2023-08-11 12:15')));

        $I->amOnPage('/index.php?/robots.txt');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Sitemap: http://localhost:8881/index.php?/sitemap.xml');
    }

    /**
     * @throws \JsonException
     */
    private function testBlogModule(AcceptanceTester $I): void
    {
        $I->amOnPage('/tags/blog tag');
        $I->seeResponseCodeIsClientError();

        $I->amOnPage('/');

        $formSelector = '.site-header-shell .post-create-template .post-inplace-edit-form';
        $token = (string)$I->grabAttributeFrom($formSelector . ' input[name="inplace_token"]', 'value');
        $publishedAt = strtotime('2023-08-12T11:32');
        $I->sendAjaxPostRequest('/_inplace/post/new', [
            'inplace_action' => 'create',
            'inplace_token'  => $token,
            'revision'       => '0',
            'title'          => 'new-post1',
            'body'           => '<p>Start text</p>',
            'tags'           => '',
            'published_at'   => (string)$publishedAt,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $created = json_decode($I->grabPageSource(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertSame('create', $created['action'] ?? null);
        $I->assertSame(self::URL_PREFIX . '/new-post1', $created['url'] ?? null);

        $postId = (int)($created['id'] ?? 0);
        $I->assertGreaterThan(0, $postId);
        $this->blogPostId = $postId;

        $I->sendAjaxPostRequest('/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $created['token'],
            'revision'       => '1',
            'title'          => 'New Blog Post Title',
            'tags'           => 'tag1, blog tag',
            'published_at'   => (string)$publishedAt,
            'body'           => '<p>New blog post</p>',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('"success":true');
        $I->see('"revision":2');

        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=new');
        $I->seeResponseCodeIs(403);
        $I->dontSeeElement('form[name="article-form"]');
        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId);
        $I->seeResponseCodeIs(403);
        $I->dontSeeElement('form[name="article-form"]');

        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=list');
        $I->dontSeeElement('a.entity-action-new');
        $I->dontSeeElement('a.list-action-link-edit');
        $I->canSee('2023-08-12');
        $I->see('New Blog Post Title');

        foreach (['/archive/2023/08/12/', '/archive/2023/08/'] as $url) {
            $I->amOnPage($url);
            $I->see('New Blog Post Title');
            $I->see('New blog post');
            $I->see('August 12, 2023');
        }

        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');
        $I->amOnPage('/new-post1');
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('August 12, 2023');
        $I->canWriteComment(
            premoderation: true,
            text: 'This is my first blog comment! 👪🐶',
            email: 'roman-blog@example.com',
        );
        $this->restoreAdminSession($I);
        $this->approveHiddenComment($I, '/new-post1', 'This is my first blog comment! 👪🐶');

        $I->amOnPage('/2023/08/12/new-post1');
        $I->seeResponseCodeIsClientError();

        $I->stopFollowingRedirects();

        $I->amOnPage('/tags/blog tag');
        $I->seeResponseCodeIs(301);
        $I->followRedirect();
        $I->seeCurrentUrlEquals(self::URL_PREFIX . '/tags/blog%20tag/');
        $I->seeResponseCodeIsSuccessful();

        $I->startFollowingRedirects();

        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('August 12, 2023');
    }

    private function testBlogRssAndSitemap(AcceptanceTester $I): void
    {
        $I->amOnPage('/index.php?/rss');
        $I->seeResponseCodeIsSuccessful();
        $I->canSee('My blog');
        $I->canSee('New Blog Post Title');
        $I->canSee('/new-post1');
        $I->canSee(gmdate('D, d M Y H:i:s', strtotime('2023-08-12 11:32:00')) . ' GMT');
        $I->see('New blog post');

        $I->amOnPage('/index.php?/sitemap.xml'); // Same as above
        $I->seeResponseCodeIsSuccessful();
        $I->see('/index.php?/sitemap-1.xml');

        $I->amOnPage('/index.php?/sitemap-1.xml');
        $I->seeResponseCodeIsSuccessful();
        $I->see('/new-post1');
    }

    private function testSearchModule(AcceptanceTester $I): void
    {
        // The preceding HTTP requests have advanced the request-driven background queue.
        $I->amOnPage('/?search=1&q=new');
        $I->see('Search', 'h1');
        $I->see('New Blog Post Title');
        $I->see('New Page Title');

        $I->amOnPage('/section1/new-page1');
        $I->submitForm('.register_search_form', ['q' => 'new']);
        $I->seeCurrentUrlEquals('/index.php?search=1&q=new');
        $I->see('Search', 'h1');
        $I->see('New Blog Post Title');
        $I->see('New Page Title');

        $I->amOnPage('/_admin/index.php?entity=SystemStatus');

        $csrfToken = $I->grabValueFrom('input[name=register_search_csrf_token]');
        $I->sendAjaxGetRequest('/_admin/ajax.php?action=register_search_reindex');
        $I->seeResponseCodeIs(405);
        $I->sendAjaxPostRequest('/_admin/ajax.php?action=register_search_reindex', ['csrf_token' => 'invalid']);
        $I->seeResponseCodeIs(403);
        $I->sendAjaxPostRequest('/_admin/ajax.php?action=register_search_reindex', ['csrf_token' => $csrfToken]);
        $I->see('queued');

        $I->amOnPage('/new-post1');
        $I->dontSeeElement('h2.recommendation-title#recommendations');
        $I->changeSetting('REGISTER_SEARCH_RECOMMENDATIONS_LIMIT', 10);
        $I->amOnPage('/new-post1');
        $I->seeElement('h2.recommendation-title#recommendations');
        $I->seeElement('div.recommendations > div.recommendation > a.recommendation-link');
        $I->see('Read next', 'h2.recommendation-title');
        $I->see('New Page Title', '.recommendation-header-2');
        $I->see('2023', '.recommendation-date');

        $I->submitForm('.register_search_form', ['q' => 'new']);
        $I->seeCurrentUrlEquals('/index.php?search=1&q=new');
        $I->see('Search', 'h1');
        $I->see('New Blog Post Title');
        $I->see('New Page Title');

        // todo save and check indexing
    }

    /**
     * @throws \JsonException
     */
    private function testAdminTagListAndEdit(AcceptanceTester $I): void
    {
        $I->amOnPage('/tags/tag1');
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/_admin/index.php?entity=Tag&action=list');
        $I->see('Tag');
        $I->see('another tag');

        $tagId = '1';
        $I->amOnPage('/_admin/index.php?entity=Tag&action=edit&id=' . $tagId);
        $dataProvider = (static fn(string $csrfToken): array => [
            '__csrf_token' => $csrfToken,
            'name'         => 'New Tag Name',
            'modify_time'  => '2023-08-12T12:15',
            'description'  => 'New tag description text',
            'url'          => 'new-tag-url1',

            'commented' => '1',
            'published' => '1',
        ]);
        $csrfToken    = $I->grabValueFrom('input[name=__csrf_token]');
        $I->sendAjaxPostRequest('/_admin/index.php?entity=Tag&action=edit&id=1111' . $tagId, $dataProvider($csrfToken));
        $this->assertJsonResponseContains($I, ['errors', 0], 'Unable to confirm security token.');

        $I->sendAjaxPostRequest('/_admin/index.php?entity=Tag&action=edit&id=' . $tagId, $dataProvider($csrfToken));
        $I->see('{"success":true}');

        $I->amOnPage('/tags/tag1');
        $I->seeResponseCodeIsClientError();

        $I->amOnPage('/tags/new-tag-url1');
        $I->seeResponseCodeIsSuccessful();
        $I->see('New tag description text');
    }

    private function testAdminCommentManagement(AcceptanceTester $I): void
    {
        $I->amOnPage('/_admin/index.php?entity=Comment&action=list');
        $I->see('This is my first comment!');

        $I->changeSetting('REGISTER_PREMODERATION', true);
        $I->changeSetting('REGISTER_WEBMASTER_EMAIL', 'webmaster@example.com');
        $I->changeSetting('REGISTER_WEBMASTER', 'Webmaster Name');

        // Set moderator email
        $I->amOnPage('/_admin/index.php?entity=User&action=list');
        $I->seeResponseCodeIsSuccessful();
        $I->submitForm('form[action="?entity=User&action=patch&field=email&id=' . 1 . '"]', [
            'email' => 'admin@example.com',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('{"success":true}');

        // This scenario intentionally posts many comments from one browser in a few seconds.
        // Raise only the acceptance installation's configurable identity limits so that the
        // test exercises comment moderation instead of tripping production rate defaults.
        foreach (['ip', 'email', 'visitor'] as $bucketType) {
            $I->amOnPage('/_admin/index.php?entity=SpamRatePolicy&action=edit&bucket_type=' . $bucketType);
            $I->submitForm('.edit-content > form', [
                'request_limit' => 1_000,
            ]);
            $I->seeResponseCodeIsSuccessful();
        }

        $this->testComments(
            $I,
            '/section1/new-page1',
            'New Page Title',
            'Some new page text',
            3,
            'page',
            'roman@example.com',
        );
        $this->testComments(
            $I,
            '/new-post1',
            'New Blog Post Title',
            'New blog post',
            $this->blogPostId,
            'post',
            'roman-blog@example.com',
        );
    }

    private function testETag(AcceptanceTester $I): void
    {
        // Disable comments and recommendations
        $I->changeSetting('REGISTER_SHOW_COMMENTS', false);
        $I->changeSetting('REGISTER_ENABLED_COMMENTS', false);
        $I->changeSetting('REGISTER_SEARCH_RECOMMENDATIONS_LIMIT', 0);

        // Test <!-- register_last_comments --> and <!-- register_last_discussions --> placeholders when comments are disabled
        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();

        // Check conditional get when the comment form is disabled. Otherwise, there are some random tokens.
        // Last comments must be also hidden.
        $I->amOnPage('/section1/new-page1');

        $headers = $I->grabHeaders();
        $I->haveHttpHeader('If-None-Match', $headers['ETag'][0]);
        $I->amOnPage('/section1/new-page1');
        $I->seeResponseCodeIs(304);
    }

    /**
     * @param string[]|int[] $path
     */
    private function assertJsonResponseContains(AcceptanceTester $I, array $path, string $needle): void
    {
        $response = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($path as $value) {
            $I->assertArrayHasKey($value, $response);
            $response = $response[$value];
        }

        $I->assertStringContainsString($needle, $response);
    }

    /**
     * @param string[] $bodyFragments
     */
    private function assertCapturedEmail(
        AcceptanceTester $I,
        string           $rawMessage,
        string           $recipient,
        string           $subjectPrefix,
        string           $replyTo,
        array            $bodyFragments,
        ?string          $unsubscribePrefix = null,
    ): ?string {
        if ($subjectPrefix === '') {
            throw new \InvalidArgumentException('A captured mail subject prefix cannot be empty.');
        }

        $to = $this->capturedHeader($rawMessage, 'To');
        $from = $this->capturedHeader($rawMessage, 'From');
        $subject = $this->capturedHeader($rawMessage, 'Subject');
        $replyToHeader = $this->capturedHeader($rawMessage, 'Reply-To');
        $contentType = $this->capturedHeader($rawMessage, 'Content-Type');

        $I->assertNotNull($to);
        $I->assertStringContainsString($recipient, $to);
        $I->assertSame('Webmaster Name <webmaster@example.com>', $from);
        $I->assertNotNull($subject);
        $I->assertStringStartsWith($subjectPrefix, $subject);
        $I->assertStringContainsString('#comment-', $subject);
        $I->assertNotNull($replyToHeader);
        $I->assertStringContainsString($replyTo, $replyToHeader);
        $I->assertSame('Register Mailer', $this->capturedHeader($rawMessage, 'X-Mailer'));
        $I->assertSame('auto-generated', $this->capturedHeader($rawMessage, 'Auto-Submitted'));
        $I->assertNotNull($contentType);
        $I->assertStringStartsWith('multipart/alternative;', $contentType);
        $I->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $rawMessage);
        $I->assertStringContainsString('Content-Type: text/html; charset=utf-8', $rawMessage);

        $decoded = quoted_printable_decode($rawMessage);
        foreach ($bodyFragments as $fragment) {
            $I->assertStringContainsString($fragment, $decoded);
        }

        $unsubscribe = $this->capturedHeader($rawMessage, 'List-Unsubscribe');
        if ($unsubscribePrefix === null) {
            $I->assertNull($unsubscribe);
            return null;
        }

        $I->assertNotNull($unsubscribe);
        $I->assertStringStartsWith('<' . $unsubscribePrefix, $unsubscribe);
        $I->assertStringEndsWith('>', $unsubscribe);
        $I->assertSame(
            'List-Unsubscribe=One-Click',
            $this->capturedHeader($rawMessage, 'List-Unsubscribe-Post'),
        );

        return substr($unsubscribe, 1, -1);
    }

    private function capturedHeader(string $rawMessage, string $name): ?string
    {
        $separator = strpos($rawMessage, "\r\n\r\n");
        if ($separator === false) {
            return null;
        }

        $headerBlock = substr($rawMessage, 0, $separator);
        $unfolded = preg_replace("/\r\n[ \t]+/", ' ', $headerBlock);
        if (!\is_string($unfolded)
            || preg_match('/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $unfolded, $matches) !== 1
        ) {
            return null;
        }

        return trim($matches[1]);
    }

    private function testComments(
        AcceptanceTester $I,
        string           $publicUrl,
        string           $pageTitle,
        string           $pageText,
        int              $targetId,
        string           $contentType,
        string           $guestEmail,
    ): void {
        $guestCommentText = 'This is my pending ' . $contentType . ' comment! 👪🐶';

        /**
         * Empty form validation and rich editor for a guest.
         */
        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');
        $I->amOnPage($publicUrl);
        $I->seeElement('#comment-form [data-comment-editor]');
        $I->seeElement('#comment-form .comment-editor-toolbar');
        $I->dontSeeElement('#comment-form .comment-preview');
        $I->seeElement('#comment-form [data-comment-guest-identity]');
        $I->click('submit');
        $I->see('You have forgotten to enter the comment text.');
        $I->see('Invalid e-mail. Please enter a valid address.');
        $I->see('You have forgotten to enter your name.');

        /**
         * Testing that a comment with an unknown email is not published when pre-moderation is enabled.
         */
        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $I->see($pageText);
        $I->canWriteComment(true, $guestCommentText, $guestEmail);

        $emails = $I->waitForEmails(1);
        $I->assertCount(1, $emails);

        $this->assertCapturedEmail(
            $I,
            $emails[0],
            'admin@example.com',
            'Comment to http://localhost:8881/index.php?' . $publicUrl,
            $guestEmail,
            [
                'Hello, admin.',
                'You have received this e-mail, because you are the moderator.',
                '“' . $pageTitle . '”,',
                'http://localhost:8881/index.php?' . $publicUrl . '#comment-',
                'Roman 🌞 is the comment author.',
                $guestCommentText,
                'Hidden: the comment failed the check (report=ham).',
                'of the comment will receive your answer.',
            ],
        );

        $this->restoreAdminSession($I);

        /**
         * Testing that a comment with known email <admin@example.com> is published when pre-moderation is enabled
         * and user is logged in
         */
        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $I->see($pageText);

        $I->sendComment('Moderator', 'admin@example.com', 'This is a comment from a moderator.');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Your comment has been successfully sent. It will be published after the verification.');
        $I->see('admin', '.comment-name');
        $I->see('This is a comment from a moderator.');

        // Email to subscribed user
        $emails = $I->waitForEmails(1);
        $I->assertCount(1, $emails);
        $this->assertCapturedEmail(
            $I,
            $emails[0],
            $guestEmail,
            'Comment to http://localhost:8881/index.php?' . $publicUrl,
            'webmaster@example.com',
            [
                'Hello, Roman 🌞.',
                'You have received this e-mail because you subscribed to comments on the content',
                '“' . $pageTitle . '”,',
                'http://localhost:8881/index.php?' . $publicUrl . '#comment-',
                'The author of the new comment is admin.',
                'This is a comment from a moderator.',
                'of the site will receive your answer. To unsubscribe, follow the link',
                'http://localhost:8881/index.php?/comment_unsubscribe&mail=' . rawurlencode($guestEmail) . '&id=' . $targetId . '&code=',
            ],
            'http://localhost:8881/index.php?/comment_unsubscribe&mail=' . rawurlencode($guestEmail) . '&id=' . $targetId . '&code=',
        );

        /**
         * Testing that a comment with known email <admin@example.com> is not published when pre-moderation is enabled
         * and user is not logged in
         */
        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');

        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $I->see($pageText);
        $I->canWriteComment(
            true,
            'This is a comment from a moderator2.',
            'admin@example.com',
            'Moderator2',
            false,
        );

        $emails = $I->waitForEmails(1);
        $I->assertCount(1, $emails);
        $this->assertCapturedEmail(
            $I,
            $emails[0],
            'admin@example.com',
            'Comment to http://localhost:8881/index.php?' . $publicUrl,
            'admin@example.com',
            [
                'Hello, admin.',
                'You have received this e-mail, because you are the moderator.',
                '“' . $pageTitle . '”,',
                'http://localhost:8881/index.php?' . $publicUrl . '#comment-',
                'Moderator2 is the comment author.',
                'This is a comment from a moderator2.',
                'Hidden: the comment failed the check (report=ham).',
                'of the comment will receive your answer.',
            ],
        );

        $this->restoreAdminSession($I);


        /**
         * Check comment notifications to subscribers after moderation approval
        */
        $I->clearEmails();

        $commentListUrl = '/_admin/index.php?entity=Comment&action=list&content_type=' . $contentType . '&content_id=' . $targetId . '&apply_filter=1';
        $I->amOnPage($commentListUrl);
        $moderator2CommentId = $this->findCommentId($I, 'This is a comment from a moderator2.');
        $moderator2PatchForm = 'form[action="?entity=Comment&action=patch&field=shown&id=' . $moderator2CommentId . '"]';
        $I->submitForm($moderator2PatchForm, [
            'shown' => 'on',
        ]);

        $emails = $I->waitForEmails(1);
        $I->assertCount(1, $emails);
        $unsubscribeLink = $this->assertCapturedEmail(
            $I,
            $emails[0],
            $guestEmail,
            'Comment to http://localhost:8881/index.php?' . $publicUrl,
            'webmaster@example.com',
            [
                'Hello, Roman 🌞.',
                'You have received this e-mail because you subscribed to comments on the content',
                '“' . $pageTitle . '”,',
                'http://localhost:8881/index.php?' . $publicUrl . '#comment-',
                'The author of the new comment is Moderator2.',
                'This is a comment from a moderator2.',
                'of the site will receive your answer. To unsubscribe, follow the link',
                'http://localhost:8881/index.php?/comment_unsubscribe&mail=' . rawurlencode($guestEmail) . '&id=' . $targetId . '&code=',
            ],
            'http://localhost:8881/index.php?/comment_unsubscribe&mail=' . rawurlencode($guestEmail) . '&id=' . $targetId . '&code=',
        );
        if ($unsubscribeLink === null) {
            throw new \RuntimeException('The subscription email does not contain an unsubscribe link.');
        }

        $I->amOnPage($publicUrl);
        $I->see('Moderator2', '.comment-name');
        $I->see('This is a comment from a moderator2.');


        /**
         * Test hiding
        */
        $I->amOnPage($commentListUrl);
        $I->uncheckOption($moderator2PatchForm . ' input[name="shown"]');
        $I->submitForm($moderator2PatchForm, []);
        $I->amOnPage($publicUrl);
        $I->seeElement('[data-comment-id="' . $moderator2CommentId . '"][data-moderation-state="hidden"]');

        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');
        $I->amOnPage($publicUrl);
        $I->dontSee('Moderator2', '.comment-name');
        $I->dontSee('This is a comment from a moderator2.');
        $this->restoreAdminSession($I);

        /**
         * Test no emails on republication
        */
        $I->clearEmails();
        $I->amOnPage($commentListUrl);
        $I->submitForm($moderator2PatchForm, [
            'shown' => 'on',
        ]);
        $I->amOnPage($publicUrl);
        $I->see('Moderator2', '.comment-name');
        $I->see('This is a comment from a moderator2.');
        $I->drainQueue();
        $I->assertCount(0, $I->getEmails());

        /**
         * Test unsubscribing
        */
        $I->sendAjaxPostRequest($unsubscribeLink, ['List-Unsubscribe' => 'invalid']);
        $I->seeResponseCodeIs(400);

        $I->sendAjaxPostRequest($unsubscribeLink, ['List-Unsubscribe' => 'One-Click']);
        $I->seeResponseCodeIs(200);
        $I->see('You have been successfully unsubscribed from mailing comments.');

        $I->amOnPage($unsubscribeLink);
        $I->seeResponseCodeIs(200);
        $I->see('Probably, you followed an incorrect or outdated link.');

        /**
         * Test no emails after unsubscribe
         */
        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $this->restoreAdminSession($I);
        $I->amOnPage($publicUrl);
        $I->sendComment('Moderator3', 'admin@example.com', 'This is a comment from a moderator3.');
        $I->see('admin', '.comment-name');
        $I->see('This is a comment from a moderator3.');
        $I->drainQueue();
        $I->assertCount(0, $I->getEmails());

        /**
         * Test deleting
        */
        $I->amOnPage($commentListUrl);

        $moderator3CommentId = $this->findCommentId($I, 'This is a comment from a moderator3.');
        $deleteUrl = '?entity=Comment&action=delete&id=' . $moderator3CommentId;
        $csrfToken = $I->grabAttributeFrom('[data-delete-url="' . $deleteUrl . '"]', 'data-csrf-token');
        if ($csrfToken === null || $csrfToken === '') {
            throw new \RuntimeException('The delete action does not contain a CSRF token.');
        }

        $I->sendAjaxPostRequest('/_admin/index.php' . $deleteUrl, ['csrf_token' => $csrfToken]);
        $I->amOnPage($publicUrl);
        $I->dontSee('Moderator3', '.comment-name');
        $I->dontSee('This is a comment from a moderator3.');
    }

    private function findCommentId(AcceptanceTester $I, string $commentText): int
    {
        $xpath = '//tr[contains(., ' . $this->xpathLiteral($commentText)
            . ')]//*[@data-admin-delete and contains(@data-delete-url, "action=delete")]';
        $deleteUrl = $I->grabAttributeFrom($xpath, 'data-delete-url');
        if ($deleteUrl === null || preg_match('/[?&]id=(\d+)/', $deleteUrl, $matches) !== 1) {
            throw new \RuntimeException(sprintf('Cannot determine the ID for comment "%s".', $commentText));
        }

        return (int)$matches[1];
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        return "concat('" . implode("', \"'\", '", explode("'", $value)) . "')";
    }

    private function getArticleCsrfToken(AcceptanceTester $I, int $articleId): string
    {
        $I->sendAjaxGetRequest('/_admin/ajax.php?action=load_tree&id=0');
        $I->seeResponseCodeIsSuccessful();

        $tree  = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($tree)) {
            throw new \RuntimeException('The article tree response must be an array.');
        }

        $token = $this->extractArticleToken($tree, $articleId);

        if ($token === null) {
            $I->fail(\sprintf('CSRF token for article %d not found in load_tree output.', $articleId));
        }

        return $token;
    }

    /** @param array<array-key, mixed> $tree */
    private function extractArticleToken(array $tree, int $articleId): ?string
    {
        foreach ($tree as $node) {
            if (!\is_array($node)) {
                continue;
            }

            $nodeId = (int)($node['attr']['data-id'] ?? 0);
            if ($nodeId === $articleId) {
                $token = $node['attr']['data-csrf-token'] ?? null;
                return \is_string($token) ? $token : null;
            }

            $children = $node['children'] ?? null;
            if (\is_array($children) && $children !== []) {
                $found = $this->extractArticleToken($children, $articleId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
