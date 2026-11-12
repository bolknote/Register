<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentIdAllocator;
use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Register\Schema\SchemaManager;
use Symfony\Component\HttpFoundation\Request;

final class BlogPageResponseCacheCest
{
    public function cachesPublicArchivesFavoritesAndFeedsAndRefreshesThemAfterAnEdit(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $id = $this->insertPost($db, 'Public collection before edit', 'collection-entry');
        $db->update(ContentSchema::TABLE_NAME)->set('featured', '1')->where('id = :id')->setParameter('id', $id)->execute();
        $paths = ['/archive/2023/11/', '/archive/2023/11/14/', '/favorite/', '/rss/', '/feed.json'];
        foreach ($paths as $path) {
            $I->sendRequestWithHeaders($path, ['User-Agent' => 'SyntheticTestBot/1.0']);
            $I->seeResponseCodeIs(200);
            $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
            $I->assertStringContainsString('Public collection before edit', $I->grabResponse());
            $I->sendRequestWithHeaders($path, ['User-Agent' => 'SyntheticTestBot/1.0']);
            $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
            /** @var PDO $pdo */
            $pdo = $I->grabService(\PDO::class);
            $I->assertSame([], $pdo->getQueryLog());
        }

        $db->update(ContentSchema::TABLE_NAME)->set('title', ':title')->setParameter('title', 'Public collection after edit')
            ->where('id = :id')->setParameter('id', $id)->execute();
        /** @var ContentChangeDispatcher $changes */
        $changes = $I->grabService(ContentChangeDispatcher::class);
        $changes->dispatch(ContentId::post($id));
        foreach ($paths as $path) {
            $I->sendRequestWithHeaders($path, ['User-Agent' => 'SyntheticTestBot/1.0']);
            $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
            $I->assertStringContainsString('Public collection after edit', $I->grabResponse());
            $I->assertStringNotContainsString('Public collection before edit', $I->grabResponse());
        }
    }

    public function cachesYearCalendarsWithoutRepeatingDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $this->insertPost($db, 'Calendar article', 'calendar-article');
        $I->sendRequestWithHeaders('/archive/2023/', ['User-Agent' => 'SyntheticTestBot/1.0']);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->sendRequestWithHeaders('/archive/2023/', ['User-Agent' => 'SyntheticTestBot/1.0']);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
        $I->assertStringContainsString('/all/calendar-article', $I->grabResponse());
    }

    public function keepsTagPagesAndFeedsSeparateAndIgnoresNeitherPaginationNorPrivateRequests(\IntegrationTester $I): void
    {
        $I->setConfigValue('REGISTER_MAX_ITEMS', '1');
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        /** @var \Register\Content\TagRepository $tags */
        $tags = $I->grabService(\Register\Content\TagRepository::class);
        $tagIds = $tags->findOrCreateIdsByNames(['cache-topic']);
        foreach ([1, 2] as $number) {
            $id = $this->insertPost($db, 'Tagged entry ' . $number, 'tagged-entry-' . $number);
            $db->update(ContentSchema::TABLE_NAME)->set('published_at', (string)(1_700_000_001 + $number))
                ->where('id = :id')->setParameter('id', $id)->execute();
            $tags->replace(ContentId::post($id), $tagIds);
        }

        $headers = ['User-Agent' => 'SyntheticTestBot/1.0'];
        foreach (['/tags/cache-topic/' => 'Tagged entry 2', '/tags/cache-topic/?p=2' => 'Tagged entry 1',
            '/tags/cache-topic/rss' => 'Tagged entry', '/tags/cache-topic/feed.json' => 'Tagged entry'] as $path => $title) {
            $I->sendRequestWithHeaders($path, $headers);
            $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
            $I->sendRequestWithHeaders($path, $headers);
            $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
            $I->assertStringContainsString($title, $I->grabResponse());
            /** @var PDO $pdo */
            $pdo = $I->grabService(\PDO::class);
            $I->assertSame([], $pdo->getQueryLog());
        }

        $I->sendRequestWithHeaders('/tags/cache-topic/?p=2&other=1', $headers);
        $I->assertNull($I->grabHttpHeader('X-Register-Page-Cache'));
        $I->sendRequestWithHeaders('/rss/', $headers + ['Authorization' => 'Bearer private']);
        $I->assertNull($I->grabHttpHeader('X-Register-Page-Cache'));
    }

    public function cachesTheFullFeedBehindConditionalRequests(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $this->insertPost($db, 'Conditional feed entry', 'conditional-entry');
        foreach (['/rss/', '/feed.json'] as $path) {
            $I->sendRequestWithHeaders($path, ['If-None-Match' => '*']);
            $I->seeResponseCodeIs(304);
            $I->sendRequestWithHeaders($path, ['User-Agent' => 'Mozilla/5.0 test reader']);
            $I->seeResponseCodeIs(200);
            $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
            $I->assertStringContainsString('Conditional feed entry', $I->grabResponse());
            $etag = (string)$I->grabHttpHeader('ETag');
            $I->sendRequestWithHeaders($path, ['If-None-Match' => $etag]);
            $I->seeResponseCodeIs(304);
            $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
            $I->assertSame('', $I->grabResponse());
        }
    }

    public function cachedFeedsStillCountDifferentReadersOncePerDay(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $this->insertPost($db, 'Feed readership fixture', 'feed-readership');
        foreach (['First feed reader', 'First feed reader', 'Second feed reader'] as $reader) {
            $I->sendRequestWithHeaders('/rss/', ['User-Agent' => $reader]);
            $I->seeResponseCodeIs(200);
        }

        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $row = $db->select('hits, unique_count')->from('register_analytics_daily')
            ->where("channel = 'feed:blog'")->execute()->fetchAssoc();
        $I->assertNotFalse($row);
        $I->assertSame(2, (int)$row['hits']);
        $I->assertSame(2, (int)$row['unique_count']);
    }

    public function aWarmRequestBootstrapsAndServesContentWithoutOpeningADatabaseConnection(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Disconnected cached post', 'disconnected-cached-post');
        $headers = ['User-Agent' => 'SyntheticTestBot/1.0'];
        $I->sendRequestWithHeaders('/disconnected-cached-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');

        $application = $I->createApplication();
        $pdo = new PDO('sqlite:/nonexistent-register-test-directory/database.sqlite', lazy: true);
        $application->container->decorate(\PDO::class, static fn(): PDO => $pdo);
        /** @var SchemaManager $schema */
        $schema = $application->container->get(SchemaManager::class);
        $I->assertFalse($schema->ensureCurrent($application->container->get(DynamicConfigProvider::class)));
        $application->container->get(ShutdownWorkCoordinator::class);

        $request = Request::create('/disconnected-cached-post');
        $request->headers->set('User-Agent', 'SyntheticTestBot/1.0');

        $response = $application->handle($request);
        $I->assertSame(200, $response->getStatusCode());
        $I->assertSame('hit', $response->headers->get('X-Register-Page-Cache'));
        $I->assertStringContainsString('Disconnected cached post', (string)$response->getContent());
        $I->assertFalse($pdo->isConnected());
        $I->assertSame([], $pdo->getQueryLog());

        $response = $application->handle(Request::create('/disconnected-cached-post?reply_to=42'));
        $I->assertSame(404, $response->getStatusCode());
        $I->assertFalse($pdo->isConnected());
    }

    public function servesTheAnonymousFirstPageHitWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached first page', 'cached-first-page');

        $I->amOnPage('/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached first page');

        $I->amOnPage('/?utm_source=integration-test');
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached first page');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function servesTheAnonymousAllPostsHitWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached archive', 'cached-archive');

        $I->amOnPage('/all/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached archive');

        $I->amOnPage('/all/?unused=1');
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached archive');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function servesRepeatedCrawlerContentWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached crawler post', 'cached-crawler-post');

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'];
        $I->sendRequestWithHeaders('/cached-crawler-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached crawler post');
        $I->dontSeeElement('#add-comment');
        $I->dontSeeElement('meta[name="register-analytics"]');
        $I->dontSeeElement('meta[name="register-visitor"]');
        $I->dontSeeElement('meta[name="register-live-updates"]');
        foreach (['analytics/collector.js', 'visitor/identity.js', 'reactions/reactions.js', 'live-updates.js'] as $script) {
            $I->assertStringNotContainsString($script, $I->grabResponse());
        }

        $I->assertStringContainsString('reactions/reactions.css', $I->grabResponse());

        $I->sendRequestWithHeaders('/cached-crawler-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached crawler post');

        $I->sendRequestWithHeaders('/cached-crawler-post', [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'Purpose' => 'prefetch',
        ]);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->dontSeeElement('meta[name="register-visitor"]');
        $I->assertStringNotContainsString('live-updates.js', $I->grabResponse());

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());

        $I->sendRequestWithHeaders('/cached-crawler-post', ['User-Agent' => 'Mozilla/5.0 integration browser']);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->seeElement('#add-comment');
        $I->seeElement('meta[name="register-analytics"]');
        $I->seeElement('meta[name="register-visitor"]');
        $I->seeElement('meta[name="register-live-updates"]');
        foreach (['analytics/collector.js', 'visitor/identity.js', 'reactions/reactions.js', 'live-updates.js'] as $script) {
            $I->assertStringContainsString($script, $I->grabResponse());
        }
    }

    public function servesRepeatedBrowserPrefetchWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached prefetched post', 'cached-prefetched-post');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'Purpose' => 'prefetch',
        ];
        $I->sendRequestWithHeaders('/cached-prefetched-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached prefetched post');
        $I->dontSeeElement('#add-comment');
        $I->dontSeeElement('meta[name="register-analytics"]');
        $I->dontSeeElement('meta[name="register-visitor"]');
        $I->dontSeeElement('meta[name="register-live-updates"]');
        foreach (['analytics/collector.js', 'visitor/identity.js', 'reactions/reactions.js', 'live-updates.js'] as $script) {
            $I->assertStringNotContainsString($script, $I->grabResponse());
        }

        $I->sendRequestWithHeaders('/cached-prefetched-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function hydratesAFreshFormAfterReusingTheBrowserContentShell(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached browser post', 'cached-browser-post');

        $headers = ['User-Agent' => 'Mozilla/5.0 integration browser'];
        $I->sendRequestWithHeaders('/cached-browser-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->seeElement('#comment-form');
        $I->see('0', '.post-foot-views-count');

        $firstTextField = (string)$I->grabAttributeFrom('#comment-text', 'name');
        $I->assertStringNotContainsString('register-deferred-comment-form', $I->grabResponse());

        $I->sendRequestWithHeaders(
            '/cached-browser-post?utm_source=test',
            $headers,
        );
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->seeElement('#comment-form');
        $I->see('0', '.post-foot-views-count');
        $I->assertSame('', $I->grabAttributeFrom('.comment-parent-id', 'value'));
        $I->assertSame('0', $I->grabAttributeFrom('.comment-reply-number', 'value'));
        $I->assertSame('', $I->grabAttributeFrom('.comment-reply-name', 'value'));
        $I->assertNotSame($firstTextField, (string)$I->grabAttributeFrom('#comment-text', 'name'));
        $I->assertStringNotContainsString('register-deferred-comment-form', $I->grabResponse());
        $I->assertStringNotContainsString('register-deferred-view-count', $I->grabResponse());

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function hydratesAValidFormInCachedPartialNavigation(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached partial post', 'cached-partial-post');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'X-Register-Navigation' => 'partial',
        ];
        $I->sendRequestWithHeaders('/cached-partial-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');

        $I->sendRequestWithHeaders('/cached-partial-post?utm_source=test', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $fragment = $payload['fragment'] ?? null;
        $I->assertIsString($fragment);
        $I->assertStringContainsString('id="comment-form"', $fragment);
        $I->assertStringContainsString('class="comment-parent-id"', $fragment);
        $I->assertStringContainsString('class="comment-parent-id"', $fragment);
        $I->assertStringNotContainsString('register-deferred-comment-form', $fragment);
    }

    public function rejectsObsoleteReplyUrlsBeforeRenderingOrRedirecting(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Reply URL test', 'reply-url-test');
        $I->amOnPage('/reply-url-test');

        foreach (['Mozilla/5.0 integration browser', 'SyntheticCrawlerBot/1.0'] as $agent) {
            foreach (['reply_to=42', 'reply_number=7', 'reply_name=Reader', 'reply_to[]=', 'reply_to='] as $query) {
                foreach (['/reply-url-test', '/reply-url-test/', '/', '/all/'] as $path) {
                    $I->sendRequestWithHeaders($path . '?' . $query, ['User-Agent' => $agent]);
                    $I->seeResponseCodeIs(404);
                    $I->dontSeeElement('#comment-form');
                    /** @var PDO $pdo */
                    $pdo = $I->grabService(\PDO::class);
                    $I->assertSame([], $pdo->getQueryLog());
                }
            }
        }

        $I->sendRequestWithMethod('HEAD', '/reply-url-test?reply_to=42');
        $I->seeResponseCodeIs(404);
    }

    public function updatesCommentSidebarsWithoutCoolingAnUnrelatedCachedPage(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        $postId = $this->insertPost($dbLayer, 'Sidebar target post', 'sidebar-target-post');
        $this->insertSidebarPage($dbLayer);

        $firstCommentId = $comments->save(
            ContentId::post($postId),
            'First sidebar author',
            '',
            false,
            'First sidebar comment',
            '',
            null,
        );
        $comments->publish($firstCommentId, ContentType::POST);

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'];
        $I->sendRequestWithHeaders('/sidebar-cache-page', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('First sidebar author');
        $I->assertStringNotContainsString('register-deferred-blog-sidebar', $I->grabResponse());

        $I->sendRequestWithHeaders('/sidebar-cache-page', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('First sidebar author');
        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());

        $secondCommentId = $comments->save(
            ContentId::post($postId),
            'Second sidebar author',
            '',
            false,
            'Second sidebar comment',
            '',
            null,
        );
        $comments->publish($secondCommentId, ContentType::POST);

        $I->sendRequestWithHeaders('/sidebar-cache-page', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Second sidebar author');
        $I->assertStringNotContainsString('register-deferred-blog-sidebar', $I->grabResponse());
    }

    public function updatesCrossPostFragmentsWithoutCoolingAnUnrelatedPost(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $firstPostId = $this->insertPost(
            $dbLayer,
            'First relation title',
            'first-relation-post',
            'Cache relation series',
        );
        $this->insertPost(
            $dbLayer,
            'Second relation title',
            'second-relation-post',
            'Cache relation series',
        );

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'];
        $I->sendRequestWithHeaders('/second-relation-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('First relation title');
        $I->assertSame('', $I->grabAttributeFrom('article.post-card', 'data-analytics-author'));
        $I->assertSame('', $I->grabAttributeFrom('article.post-card', 'data-analytics-section'));
        $I->assertSame('1700000001', $I->grabAttributeFrom('article.post-card', 'data-analytics-published-at'));
        $I->assertStringNotContainsString('<nobr>data-analytics-', $I->grabResponse());
        $I->assertStringNotContainsString('data-analytics-section=»', $I->grabResponse());

        $I->sendRequestWithHeaders('/second-relation-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('title', ':title')->setParameter('title', 'Revised relation title')
            ->where('id = :id')->setParameter('id', $firstPostId)
            ->execute()
        ;
        /** @var ContentChangeDispatcher $changeDispatcher */
        $changeDispatcher = $I->grabService(ContentChangeDispatcher::class);
        $changeDispatcher->dispatch(ContentId::post($firstPostId));

        $I->sendRequestWithHeaders('/second-relation-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Revised relation title');
        $I->dontSee('First relation title');
        $I->assertStringNotContainsString('register-deferred-post-context', $I->grabResponse());
        $I->assertStringNotContainsString('register-deferred-recommendations', $I->grabResponse());
    }

    private function insertPost(DbLayer $dbLayer, string $title, string $slug, string $series = ''): int
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', 1_700_000_001)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Text</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', ':series')->setParameter('series', $series)
            ->setValue('slug', ':slug')->setParameter('slug', $slug)
            ->setValue('author_id', 'NULL')
            ->setValue('id', (string)ContentIdAllocator::allocate($dbLayer))
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertSidebarPage(DbLayer $dbLayer): int
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('parent_id', '1')
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', '1700000001')
            ->setValue('published_at', '1700000001')
            ->setValue('updated_at', '1700000001')
            ->setValue('revision', '1')
            ->setValue('title', "'Sidebar cache page'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Sidebar cache body</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '0')
            ->setValue('sort_order', '0')
            ->setValue('slug', "'sidebar-cache-page'")
            ->setValue('template', "'mainpage.php'")
            ->setValue('id', (string)ContentIdAllocator::allocate($dbLayer))
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }
}
