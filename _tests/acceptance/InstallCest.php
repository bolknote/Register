<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace acceptance;

use AcceptanceTester;
use Codeception\Example;

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

        $I->install('admin', 'passwd', $example['db_type'], $example['db_user'], $example['db_password']);

        $I->amOnPage('/');
        $I->see('Register');
        $I->see('A place to write');
        $I->see('Register is a blog engine, not a universal site builder');
        $I->seeElement('meta[name="Generator"][content="Register"]');
        $I->seeElement('link[href$="/_styles/register/favicon.svg"]');
        $I->seeElement('script[src$="/_assets/register/syntax-highlighting/loader.js"]');
        $I->seeElement('script[src$="/_assets/register/audio-player/loader.js"]');
        $I->seeElement('a.visual-login[href$="/_admin/index.php"]');
        $I->see('ℜ', 'a.visual-login');
        $I->dontSeeElement('a.visual-login svg');
        $I->amOnPage('/?search=1&q=personal+blog');
        $I->see('A place to write');
        $I->see('small, fast engine');
        $I->amOnPage('/section1/page1');
        $I->see('Register was installed successfully.');
        $I->canWriteComment();

        $this->testHierarchyRedirects($I);
        $this->testAdminLogin($I);
        $this->testBaseModules($I);
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

        $I->login('admin', 'passwd');
        $I->seeResponseCodeIs(200);
        $I->dontSee('You have entered incorrect username or password.');

        $I->amOnPage('/---');
        $I->see('👤 admin');
    }

    private function testBaseModules(AcceptanceTester $I): void
    {
        $I->amOnPage('/_admin/index.php?entity=SystemModules');
        $I->see('System modules', 'h1');

        foreach (['s2_blog', 's2_search', 's2_latex', 'register_visitor_identity', 's2_counter', 'register_reactions', 's2_typo', 'register_syntax_highlighting', 'register_audio_player'] as $moduleId) {
            $I->seeElement('.base-module [title=' . $moduleId . ']');
            $I->dontSeeElement('.extension.available [title=' . $moduleId . ']');
            $I->dontSeeElement('.extension:not(.base-module) [title=' . $moduleId . ']');
        }

        $I->dontSeeElement('.base-module button');
        $I->seeElement('link[href$="/_assets/register/blog/admin.css"]');
        $I->dontSeeElement('link[href*="/_extensions/s2_blog/"]');
        $I->seeElement('link[href$="/_assets/register/math/math.css"]');
        $I->seeElement('script[src$="/_assets/register/math/loader.js"]');
        $I->dontSeeElement('script[src*="/_extensions/s2_latex/"]');

        $I->amOnPage('/_admin/index.php?entity=Extension');
        $I->dontSee('Built-in modules', 'h2');
        $I->see('Optional modules available for install or upgrade', 'h2');

        $I->amOnPage('/_admin/index.php?entity=Dashboard');
        $I->see('Analytics', 'h2');
        $I->seeElement('script[src$="/_assets/register/analytics/highstock.js"]');
        $I->seeElement('script[src$="/_assets/register/analytics/charts.js"]');
        $I->dontSeeElement('script[src*="/_extensions/s2_counter/"]');
        $I->seeElement('script[src$="/_assets/register/search/index-manager.js"]');
        $I->dontSeeElement('script[src*="/_extensions/s2_search/"]');
        $I->seeElement('input[name=register_search_csrf_token]');
        $I->seeElement('a[href="https://github.com/bolknote/Register"]');
        $I->dontSeeElement('a[href="https://github.com/parpalak/s2"]');

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

        $I->see('See in blog', '.header.s2_blog_tags');
        $I->see('tag1', '.s2_blog_tags a');

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
        $I->amOnPage('/index.php?/rss.xml'); // Other URL scheme because the built-in PHP server looks for a file rss.xml
        $I->seeResponseCodeIsSuccessful();
        $I->canSee('Register');
        $I->canSee('My blog');
        $I->canSee('New Blog Post Title');
        $I->canSee('/new-post1');
        $I->canSee(gmdate('D, d M Y H:i:s', strtotime('2023-08-12 11:32:00')) . ' GMT');
        $I->see('New blog post');
        $I->dontSee('New Page Title');

        $lastModified = $I->grabHeaders()['Last-Modified'][0]
            ?? throw new \RuntimeException('The blog RSS response has no Last-Modified header.');
        $I->haveHttpHeader('If-Modified-Since', $lastModified);
        $I->amOnPage('/index.php?/rss.xml');
        $I->seeResponseCodeIs(304);
        $I->dontSee('New Blog Post Title');
        $I->unsetHttpHeader('If-Modified-Since');

        $I->amOnPage('/index.php?/sitemap.xml'); // Same as above
        $I->seeResponseCodeIsSuccessful();
        $I->see('/section1/new-page1');
        $I->see('/new-post1');
        $I->see(gmdate('c', strtotime('2023-08-11 12:15')));
    }

    /**
     * @throws \JsonException
     */
    private function testBlogModule(AcceptanceTester $I): void
    {
        $I->amOnPage('/tags/blog tag');
        $I->seeResponseCodeIsClientError();

        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=new');
        $I->submitForm('form', [
            'title' => 'Привет, мир!',
            'body'  => '<p>Start text</p>',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $postId           = $I->grabFromCurrentUrl('~id=(\d+)~');
        $this->blogPostId = (int)$postId;
        $csrfToken        = $I->grabValueFrom('input[name=__csrf_token]');
        $I->assertSame('privet-mir', $I->grabValueFrom('input[name=slug]'));

        $dataProvider = (static fn(string $csrfToken): array => [
            '__csrf_token' => $csrfToken,
            'title'        => 'New Blog Post Title',
            'tags'         => 'tag1, blog tag',
            'published_at' => '2023-08-12T11:32',
            'date_label'   => '',
            'updated_at'   => '2023-08-12T12:15',
            'body'         => '<p>New blog post</p>',
            'author_id'    => '1',
            'series'       => '',
            'revision'     => '1',
            'slug'         => 'new-post1',

            'comments_enabled' => '1',
            'published'        => '1',
        ]);
        $I->sendAjaxPostRequest('/_admin/index.php?entity=BlogPost&action=edit&id=333', $dataProvider($csrfToken));
        $this->assertJsonResponseContains($I, ['errors', 0], 'Unable to confirm security token.');

        $I->sendAjaxPostRequest('/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId, $dataProvider($csrfToken));
        $I->seeResponseCodeIsSuccessful();
        $I->see('"success":true');
        $I->see('"urlStatus":"ok"');
        $I->see('"urlTitle":""');
        $I->see('"revision":"2"');

        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId);

        $postText = $I->grabValueFrom('textarea[name=body]');
        $I->assertStringContainsString('New blog post', $postText);

        $I->amOnPage('/_admin/index.php?entity=BlogPost&action=list');
        $I->canSee('2023-08-12');
        $I->see('New Blog Post Title');

        foreach (['/archive/2023/08/12/', '/archive/2023/08/'] as $url) {
            $I->amOnPage($url);
            $I->see('New Blog Post Title');
            $I->see('New blog post');
            $I->see('August 12, 2023');
        }

        $I->amOnPage('/new-post1');
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('August 12, 2023');
        $I->canWriteComment(text: 'This is my first blog comment! 👪🐶');

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
        $I->amOnPage('/index.php?/rss.xml'); // Other URL scheme because the built-in PHP server looks for a file rss.xml
        $I->seeResponseCodeIsSuccessful();
        $I->canSee('My blog');
        $I->canSee('New Blog Post Title');
        $I->canSee('/new-post1');
        $I->canSee(gmdate('D, d M Y H:i:s', strtotime('2023-08-12 11:32:00')) . ' GMT');
        $I->see('New blog post');

        $I->amOnPage('/index.php?/sitemap.xml'); // Same as above
        $I->seeResponseCodeIsSuccessful();
        $I->see('/new-post1');
        $I->see(gmdate('c', strtotime('2023-08-12 12:15')));
    }

    private function testSearchModule(AcceptanceTester $I): void
    {
        // The preceding HTTP requests have advanced the request-driven background queue.
        $I->amOnPage('/?search=1&q=new');
        $I->see('Search', 'h1');
        $I->see('New Blog Post Title');
        $I->see('New Page Title');

        $I->amOnPage('/section1/new-page1');
        $I->submitForm('.s2_search_form', ['q' => 'new']);
        $I->seeCurrentUrlEquals('/index.php?search=1&q=new');
        $I->see('Search', 'h1');
        $I->see('New Blog Post Title');
        $I->see('New Page Title');

        $I->amOnPage('/_admin/index.php?entity=Dashboard');

        $csrfToken = $I->grabValueFrom('input[name=register_search_csrf_token]');
        $I->sendAjaxGetRequest('/_admin/ajax.php?action=register_search_reindex');
        $I->seeResponseCodeIs(405);
        $I->sendAjaxPostRequest('/_admin/ajax.php?action=register_search_reindex', ['csrf_token' => 'invalid']);
        $I->seeResponseCodeIs(403);
        $I->sendAjaxPostRequest('/_admin/ajax.php?action=register_search_reindex', ['csrf_token' => $csrfToken]);
        $I->see('go_20');
        $I->sendAjaxPostRequest('/_admin/ajax.php?action=register_search_reindex', ['csrf_token' => $csrfToken]);
        $I->see('stop');

        $I->amOnPage('/new-post1');
        $I->dontSeeElement('h2.recommendation-title#recommendations');
        $I->changeSetting('S2_SEARCH_RECOMMENDATIONS_LIMIT', 10);
        $I->amOnPage('/new-post1');
        $I->seeElement('h2.recommendation-title#recommendations');
        $I->seeElement('div.recommendations > div.recommendation > a.recommendation-link');
        $I->see('Read next', 'h2.recommendation-title');
        $I->see('New Page Title', '.recommendation-header-2');
        $I->see('2023', '.recommendation-date');

        $I->submitForm('.s2_search_form', ['q' => 'new']);
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

        $I->changeSetting('S2_PREMODERATION', true);
        $I->changeSetting('S2_WEBMASTER_EMAIL', 'webmaster@example.com');
        $I->changeSetting('S2_WEBMASTER', 'Webmaster Name');

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
            $I->submitForm('form', [
                'request_limit' => 1_000,
            ]);
            $I->seeResponseCodeIsSuccessful();
        }

        $this->testComments($I, '/section1/new-page1', 'New Page Title', 'Some new page text', 3, 'page');
        $this->testComments($I, '/new-post1', 'New Blog Post Title', 'New blog post', $this->blogPostId, 'post');
    }

    private function testETag(AcceptanceTester $I): void
    {
        // Disable comments and recommendations
        $I->changeSetting('S2_SHOW_COMMENTS', false);
        $I->changeSetting('S2_ENABLED_COMMENTS', false);
        $I->changeSetting('S2_SEARCH_RECOMMENDATIONS_LIMIT', 0);

        // Test <!-- s2_last_comments --> and <!-- s2_last_discussions --> placeholders when comments are disabled
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

    private function testComments(
        AcceptanceTester $I,
        string           $publicUrl,
        string           $pageTitle,
        string           $pageText,
        int              $targetId,
        string           $contentType,
    ): void {
        /**
         * Empty form validation and preview
         */
        $I->amOnPage($publicUrl);
        $I->click('submit');
        $I->see('You have forgotten to enter the comment text.');
        $I->see('Invalid e-mail. Please enter the correct e-mail');
        $I->see('You have forgotten to enter your name.');

        $I->fillField('name', 'Tester Name');
        $I->fillField('email', 'tester@example.com');
        $I->fillField('text', 'This is a test comment');
        $I->click('preview');
        $I->see('Your comment has not been saved yet!');
        $I->see('Tester Name');
        $I->see('This is a test comment');

        /**
         * Testing that a comment with unknown email <roman@example.com> is not published when pre-moderation is enabled
         */
        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $I->see($pageText);
        $I->canWriteComment(true);

        $emails = $I->getEmails();
        $I->assertCount(1, $emails);

        // Two asserts to skip variable "Date" header
        $I->assertStringContainsString('To: admin@example.com' . "\r\n" .
            'Subject: =?UTF-8?B?' . base64_encode('Comment to http://localhost:8881/index.php?' . $publicUrl) . '?=' . "\r\n" .
            'From: =?UTF-8?B?V2VibWFzdGVyIE5hbWU=?= <webmaster@example.com>' . "\r\n" .
            'Sender: =?UTF-8?B?Um9tYW4g8J+Mng==?= <roman@example.com>' . "\r\n" .
            'Date: ', $emails[0]);

        $I->assertStringContainsString(' +0000' . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-transfer-encoding: 8bit' . "\r\n" .
            'Content-type: text/plain; charset=utf-8' . "\r\n" .
            'X-Mailer: Register Mailer' . "\r\n" .
            'Reply-To: =?UTF-8?B?Um9tYW4g8J+Mng==?= <roman@example.com>' . "\r\n" .
            '' . "\r\n" .
            'Hello, admin.' . "\r\n" .
            '' . "\r\n" .
            'You have received this e-mail, because you are the moderator.' . "\r\n" .
            'A new comment on' . "\r\n" .
            '“' . $pageTitle . '”,' . "\r\n" .
            'has been received. You can find it here:' . "\r\n" .
            'http://localhost:8881/index.php?' . $publicUrl . "\r\n" .
            '' . "\r\n" .
            'Roman 🌞 is the comment author.' . "\r\n" .
            '' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            'This is my first comment! 👪🐶' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            '' . "\r\n" .
            'Hidden: the comment failed the check (report=ham). Publish it if it is appropriate.' . "\r\n" .
            '' . "\r\n" .
            'This e-mail has been sent automatically. If you reply, the author' . "\r\n" .
            'of the comment will receive your answer.' . "\r\n" .
            '', $emails[0]);

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
        $I->see('Moderator', '.comment-name');
        $I->see('This is a comment from a moderator.');

        // Email to subscribed user
        $emails = $I->getEmails();
        $I->assertCount(1, $emails);
        $I->assertStringContainsString('To: roman@example.com' . "\r\n" .
            'Subject: =?UTF-8?B?' . base64_encode('Comment to http://localhost:8881/index.php?' . $publicUrl) . '?=' . "\r\n" .
            'From: =?UTF-8?B?' . base64_encode('Webmaster Name') . '?= <webmaster@example.com>' . "\r\n" .
            'Date: ', $emails[0]);
        $I->assertStringContainsString(' +0000' . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-transfer-encoding: 8bit' . "\r\n" .
            'Content-type: text/plain; charset=utf-8' . "\r\n" .
            'X-Mailer: Register Mailer' . "\r\n" .
            'List-Unsubscribe: <http://localhost:8881/index.php?/comment_unsubscribe&mail=roman%40example.com&id=' . $targetId . '&code='
            , $emails[0]);
        $I->assertStringContainsString(
            'Reply-To: =?UTF-8?B?' . base64_encode('Webmaster Name') . '?= <webmaster@example.com>' . "\r\n" .
            '' . "\r\n" .
            'Hello, Roman 🌞.' . "\r\n" .
            '' . "\r\n" .
            'You have received this e-mail, because you have subscribed for the article' . "\r\n" .
            '“' . $pageTitle . '”,' . "\r\n" .
            'located at the address:' . "\r\n" .
            'http://localhost:8881/index.php?' . $publicUrl . "\r\n" .
            '' . "\r\n" .
            'The author of the new comment is Moderator.' . "\r\n" .
            '' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            'This is a comment from a moderator.' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            '' . "\r\n" .
            'This e-mail has been sent automatically. If you reply, the author' . "\r\n" .
            'of the site will receive your answer. To unsubscribe, follow the link' . "\r\n" .
            '' . "\r\n" .
            'http://localhost:8881/index.php?/comment_unsubscribe&mail=roman%40example.com&id=' . $targetId . '&code='
            , $emails[0]);

        /**
         * Testing that a comment with known email <admin@example.com> is not published when pre-moderation is enabled
         * and user is not logged in
         */
        $commentCookie = $I->grabCookie($this->getCookieName() . '_c');
        $I->setCookie($this->getCookieName() . '_c', 'wrong_value');

        $I->clearEmails();
        $I->amOnPage($publicUrl);
        $I->see($pageText);
        $I->sendComment('Moderator2', 'admin@example.com', 'This is a comment from a moderator2.');
        $I->seeResponseCodeIs(200);
        $I->see('Your comment has been successfully sent. It will be published after the verification.');
        $I->dontSee('Moderator2', '.comment-name');
        $I->dontSee('This is a comment from a moderator2.');

        $emails = $I->getEmails();
        $I->assertCount(1, $emails);
        $I->assertStringContainsString('To: admin@example.com' . "\r\n" .
            'Subject: =?UTF-8?B?' . base64_encode('Comment to http://localhost:8881/index.php?' . $publicUrl) . '?=' . "\r\n" .
            'From: =?UTF-8?B?V2VibWFzdGVyIE5hbWU=?= <webmaster@example.com>' . "\r\n" .
            'Sender: =?UTF-8?B?TW9kZXJhdG9yMg==?= <admin@example.com>' . "\r\n" .
            'Date: ', $emails[0]);

        $I->assertStringContainsString(' +0000' . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-transfer-encoding: 8bit' . "\r\n" .
            'Content-type: text/plain; charset=utf-8' . "\r\n" .
            'X-Mailer: Register Mailer' . "\r\n" .
            'Reply-To: =?UTF-8?B?TW9kZXJhdG9yMg==?= <admin@example.com>' . "\r\n" .
            '' . "\r\n" .
            'Hello, admin.' . "\r\n" .
            '' . "\r\n" .
            'You have received this e-mail, because you are the moderator.' . "\r\n" .
            'A new comment on' . "\r\n" .
            '“' . $pageTitle . '”,' . "\r\n" .
            'has been received. You can find it here:' . "\r\n" .
            'http://localhost:8881/index.php?' . $publicUrl . "\r\n" .
            '' . "\r\n" .
            'Moderator2 is the comment author.' . "\r\n" .
            '' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            'This is a comment from a moderator2.' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            '' . "\r\n" .
            'Hidden: the comment failed the check (report=unknown). Publish it if it is appropriate.' . "\r\n" .
            '' . "\r\n" .
            'This e-mail has been sent automatically. If you reply, the author' . "\r\n" .
            'of the comment will receive your answer.' . "\r\n" .
            '', $emails[0]);


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

        $emails = $I->getEmails();
        $I->assertCount(1, $emails);
        $I->assertStringContainsString('To: roman@example.com' . "\r\n" .
            'Subject: =?UTF-8?B?' . base64_encode('Comment to http://localhost:8881/index.php?' . $publicUrl) . '?=' . "\r\n" .
            'From: =?UTF-8?B?' . base64_encode('Webmaster Name') . '?= <webmaster@example.com>' . "\r\n" .
            'Date: ', $emails[0]);
        $I->assertStringContainsString(
            'Hello, Roman 🌞.' . "\r\n" .
            '' . "\r\n" .
            'You have received this e-mail, because you have subscribed for the article' . "\r\n" .
            '“' . $pageTitle . '”,' . "\r\n" .
            'located at the address:' . "\r\n" .
            'http://localhost:8881/index.php?' . $publicUrl . "\r\n" .
            '' . "\r\n" .
            'The author of the new comment is Moderator2.' . "\r\n" .
            '' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            'This is a comment from a moderator2.' . "\r\n" .
            '----------------------------------------------------------------------' . "\r\n" .
            '' . "\r\n" .
            'This e-mail has been sent automatically. If you reply, the author' . "\r\n" .
            'of the site will receive your answer. To unsubscribe, follow the link' . "\r\n" .
            '' . "\r\n" .
            'http://localhost:8881/index.php?/comment_unsubscribe&mail=roman%40example.com&id=' . $targetId . '&code='
            , $emails[0]);

        if (preg_match('#List-Unsubscribe: <([^<]+)>#', $emails[0], $matches) !== 1) {
            throw new \RuntimeException('The subscription email does not contain an unsubscribe link.');
        }

        $unsubscribeLink = $matches[1];

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
        $I->dontSee('Moderator2', '.comment-name');
        $I->dontSee('This is a comment from a moderator2.');

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
        $I->assertCount(0, $I->getEmails());

        /**
         * Test unsubscribing
         */
        $I->amOnPage($unsubscribeLink);
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
        $I->setCookie($this->getCookieName() . '_c', $commentCookie);
        $I->sendComment('Moderator3', 'admin@example.com', 'This is a comment from a moderator3.');
        $I->see('Moderator3', '.comment-name');
        $I->see('This is a comment from a moderator3.');
        $I->assertCount(0, $I->getEmails());

        /**
         * Test deleting
        */
        $I->amOnPage($commentListUrl);

        $moderator3CommentId = $this->findCommentId($I, 'This is a comment from a moderator3.');
        $deleteUrl = '?entity=Comment&action=delete&id=' . $moderator3CommentId;
        $onClickHandler = $I->grabAttributeFrom('[href="' . $deleteUrl . '"]', 'onclick');
        if ($onClickHandler === null || ($tokenPosition = strrpos($onClickHandler, 'csrf_token=')) === false) {
            throw new \RuntimeException('The delete action does not contain a CSRF token.');
        }

        $csrfToken = substr($onClickHandler, $tokenPosition + 11, 40);
        $I->sendAjaxPostRequest('/_admin/index.php' . $deleteUrl, ['csrf_token' => $csrfToken]);
        $I->amOnPage($publicUrl);
        $I->dontSee('Moderator3', '.comment-name');
        $I->dontSee('This is a comment from a moderator3.');
    }

    private function findCommentId(AcceptanceTester $I, string $commentText): int
    {
        $xpath = '//tr[contains(., ' . $this->xpathLiteral($commentText) . ')]//a[contains(@href, "action=delete")]';
        $href  = $I->grabAttributeFrom($xpath, 'href');
        if ($href === null || preg_match('/[?&]id=(\d+)/', $href, $matches) !== 1) {
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
