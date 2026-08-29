<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Blog\Model;

use PHPUnit\Framework\TestCase;
use Register\Content\ContentId;
use Register\Core\Pdo\PDO;
use Register\Module\Blog\Model\AllPostsPage;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\BlogSidebarFeed;
use Register\Module\Blog\Model\PostFeed;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\HttpFoundation\Response;

final class BlogPageCacheTest extends TestCase
{
    public function testFragmentsAreReusedUntilTheirRelevantInvalidation(): void
    {
        $cache = new BlogPageCache(new ArrayAdapter());
        $firstPageBuilds = 0;
        $allPostsBuilds  = 0;
        $navigationBuilds = 0;

        $firstFactory = static function () use (&$firstPageBuilds): PostFeed {
            ++$firstPageBuilds;

            return new PostFeed('feed-' . $firstPageBuilds, null, '/skip/20');
        };
        $allFactory = static function () use (&$allPostsBuilds): AllPostsPage {
            ++$allPostsBuilds;

            return new AllPostsPage('All ' . $allPostsBuilds, 'index-' . $allPostsBuilds);
        };
        $navigationFactory = static function () use (&$navigationBuilds): array {
            ++$navigationBuilds;

            return ['build' => $navigationBuilds];
        };

        self::assertSame('feed-1', $cache->firstPage($firstFactory)->html);
        self::assertSame('feed-1', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);
        self::assertSame(['build' => 1], $cache->navigation($navigationFactory));
        self::assertSame(['build' => 1], $cache->navigation($navigationFactory));
        self::assertSame(1, $firstPageBuilds);
        self::assertSame(1, $allPostsBuilds);

        $cache->invalidateFirstPage();
        self::assertSame('feed-2', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);

        $cache->invalidateAll();
        self::assertSame('feed-3', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-2', $cache->allPosts($allFactory)->html);
        self::assertSame(['build' => 2], $cache->navigation($navigationFactory));
    }

    public function testCommentSidebarFragmentsHaveIndependentEventAndClockInvalidation(): void
    {
        $clock = new BlogPageCacheTestClock(1_000);
        $cache = new BlogPageCache(new ArrayAdapter(), clock: $clock(...));
        $commentBuilds = 0;
        $discussionBuilds = 0;
        $comments = static function () use (&$commentBuilds): BlogSidebarFeed {
            ++$commentBuilds;

            return new BlogSidebarFeed([['build' => $commentBuilds]]);
        };
        $discussions = static function () use (&$discussionBuilds): BlogSidebarFeed {
            ++$discussionBuilds;

            return new BlogSidebarFeed(
                [['build' => $discussionBuilds]],
                1_000 + $discussionBuilds * 100,
            );
        };

        self::assertSame([['build' => 1]], $cache->recentComments($comments));
        self::assertSame([['build' => 1]], $cache->recentComments($comments));
        self::assertSame([['build' => 1]], $cache->recentDiscussions($discussions));

        $clock->now = 1_099;
        self::assertSame([['build' => 1]], $cache->recentDiscussions($discussions));
        $clock->now = 1_100;
        self::assertSame([['build' => 2]], $cache->recentDiscussions($discussions));
        self::assertSame([['build' => 1]], $cache->recentComments($comments));

        $cache->invalidateCommentFragments();
        self::assertSame([['build' => 2]], $cache->recentComments($comments));
        self::assertSame([['build' => 3]], $cache->recentDiscussions($discussions));
    }

    public function testCommentChangeInvalidatesOnlyTheAffectedPostAndCommentDependencies(): void
    {
        $pool = new ArrayAdapter();
        $cache = new BlogPageCache($pool);
        $firstBuilds = 0;
        $allBuilds = 0;
        $firstContentBuilds = 0;
        $secondContentBuilds = 0;
        $sidebarBuilds = 0;

        $firstPage = static function () use (&$firstBuilds): PostFeed {
            return new PostFeed('first-' . ++$firstBuilds, null, null);
        };
        $allPosts = static function () use (&$allBuilds): AllPostsPage {
            return new AllPostsPage('all', 'all-' . ++$allBuilds);
        };
        $firstContent = static function () use (&$firstContentBuilds): Response {
            return new Response('content-one-' . ++$firstContentBuilds);
        };
        $secondContent = static function () use (&$secondContentBuilds): Response {
            return new Response('content-two-' . ++$secondContentBuilds);
        };
        $sidebar = static function () use (&$sidebarBuilds): BlogSidebarFeed {
            return new BlogSidebarFeed([['build' => ++$sidebarBuilds]]);
        };

        self::assertSame('first-1', $cache->firstPage($firstPage)->html);
        self::assertSame('all-1', $cache->allPosts($allPosts)->html);
        self::assertSame('content-one-1', $cache->contentResponse('full_bot', '/one', $firstContent)->getContent());
        $cache->rememberContentPath(ContentId::post(1), '/one');
        self::assertSame('content-two-1', $cache->contentResponse('full_bot', '/two', $secondContent)->getContent());
        $cache->rememberContentPath(ContentId::post(2), '/two');
        self::assertSame([['build' => 1]], $cache->recentComments($sidebar));

        $cache->invalidateCommentChange(ContentId::post(1));

        self::assertSame('first-2', $cache->firstPage($firstPage)->html);
        self::assertSame('all-1', $cache->allPosts($allPosts)->html);
        self::assertSame('content-one-2', $cache->contentResponse('full_bot', '/one', $firstContent)->getContent());
        self::assertSame('content-two-1', $cache->contentResponse('full_bot', '/two', $secondContent)->getContent());
        self::assertSame([['build' => 2]], $cache->recentComments($sidebar));
    }

    public function testImportedCommentInvalidationIsCoalescedUntilCommit(): void
    {
        $pool = new ArrayAdapter();
        $pdo = new PDO('sqlite::memory:');
        $cache = new BlogPageCache($pool, pdo: $pdo);
        $firstBuilds = 0;
        $sidebarBuilds = 0;
        $firstPage = static function () use (&$firstBuilds): PostFeed {
            return new PostFeed('first-' . ++$firstBuilds, null, null);
        };
        $sidebar = static function () use (&$sidebarBuilds): BlogSidebarFeed {
            return new BlogSidebarFeed([['build' => ++$sidebarBuilds]]);
        };

        self::assertSame('first-1', $cache->firstPage($firstPage)->html);
        self::assertSame([['build' => 1]], $cache->recentComments($sidebar));

        $pdo->beginTransaction();
        $cache->invalidateCommentChange(ContentId::post(1), deferUntilCommit: true);
        $cache->invalidateCommentChange(ContentId::post(1), deferUntilCommit: true);
        self::assertSame('first-1', $cache->firstPage($firstPage)->html);
        self::assertSame([['build' => 1]], $cache->recentComments($sidebar));
        $pdo->commit();

        self::assertSame('first-2', $cache->firstPage($firstPage)->html);
        self::assertSame([['build' => 2]], $cache->recentComments($sidebar));
    }

    public function testRolledBackImportLeavesCommentCachesWarm(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $cache = new BlogPageCache(new ArrayAdapter(), pdo: $pdo);
        $firstBuilds = 0;
        $sidebarBuilds = 0;
        $firstPage = static function () use (&$firstBuilds): PostFeed {
            return new PostFeed('first-' . ++$firstBuilds, null, null);
        };
        $sidebar = static function () use (&$sidebarBuilds): BlogSidebarFeed {
            return new BlogSidebarFeed([['build' => ++$sidebarBuilds]]);
        };

        self::assertSame('first-1', $cache->firstPage($firstPage)->html);
        self::assertSame([['build' => 1]], $cache->recentComments($sidebar));

        $pdo->beginTransaction();
        $cache->invalidateCommentChange(ContentId::post(1), deferUntilCommit: true);
        $pdo->rollBack();

        self::assertSame('first-1', $cache->firstPage($firstPage)->html);
        self::assertSame([['build' => 1]], $cache->recentComments($sidebar));
        self::assertSame(1, $firstBuilds);
        self::assertSame(1, $sidebarBuilds);
    }

    public function testCompleteResponsesAreReusedAndInvalidatedWithTheirPage(): void
    {
        $cache = new BlogPageCache(new ArrayAdapter());
        $firstBuilds = 0;
        $allBuilds = 0;

        $firstFactory = static function () use (&$firstBuilds): Response {
            ++$firstBuilds;

            return new Response('first-' . $firstBuilds, headers: ['ETag' => 'first-etag']);
        };
        $allFactory = static function () use (&$allBuilds): Response {
            ++$allBuilds;

            return new Response('all-' . $allBuilds);
        };

        $firstMiss = $cache->firstResponse('full_new_visitor', $firstFactory);
        self::assertSame('first-1', $firstMiss->getContent());
        self::assertSame('miss', $firstMiss->headers->get('X-Register-Page-Cache'));
        self::assertSame('first-etag', $firstMiss->headers->get('ETag'));

        $firstHit = $cache->firstResponse('full_new_visitor', $firstFactory);
        self::assertSame('first-1', $firstHit->getContent());
        self::assertSame('hit', $firstHit->headers->get('X-Register-Page-Cache'));
        self::assertSame(1, $firstBuilds);

        self::assertSame('all-1', $cache->allResponse('full_new_visitor', $allFactory)->getContent());
        self::assertSame('all-1', $cache->allResponse('full_new_visitor', $allFactory)->getContent());
        self::assertSame(1, $allBuilds);

        $cache->invalidateFirstPage();
        self::assertSame('first-2', $cache->firstResponse('full_new_visitor', $firstFactory)->getContent());
        self::assertSame('all-1', $cache->allResponse('full_new_visitor', $allFactory)->getContent());

        $cache->invalidateAll();
        self::assertSame('all-2', $cache->allResponse('full_new_visitor', $allFactory)->getContent());
    }

    public function testClockDependentResponseChangesOnlyAtItsSemanticBoundary(): void
    {
        $clock = new BlogPageCacheTestClock(1_000);
        $cache = new BlogPageCache(
            new ArrayAdapter(),
            clock: $clock(...),
        );
        $builds = 0;
        $factory = function () use ($cache, &$builds): Response {
            ++$builds;
            $cache->invalidateCurrentResponseAt(1_100);

            return new Response('semantic-' . $builds);
        };

        self::assertSame('semantic-1', $cache->firstResponse('full_bot', $factory)->getContent());
        $clock->now = 1_099;
        self::assertSame('semantic-1', $cache->firstResponse('full_bot', $factory)->getContent());
        $clock->now = 1_100;
        self::assertSame('semantic-2', $cache->firstResponse('full_bot', $factory)->getContent());
        self::assertSame(2, $builds);
    }

    public function testPublishedAuthorMultiplicityIsReusedUntilContentInvalidation(): void
    {
        $cache = new BlogPageCache(new ArrayAdapter());
        $lookups = 0;
        $factory = static function () use (&$lookups): bool {
            ++$lookups;

            return $lookups > 1;
        };

        self::assertFalse($cache->multiplePublishedAuthors($factory));
        self::assertFalse($cache->multiplePublishedAuthors($factory));

        $cache->invalidateFirstPage();
        self::assertFalse($cache->multiplePublishedAuthors($factory));

        $cache->invalidateAll();
        self::assertTrue($cache->multiplePublishedAuthors($factory));
    }

    public function testContentResponsesUsePathMappingsForTargetedAndGlobalInvalidation(): void
    {
        $pool = new ArrayAdapter();
        $cache = new BlogPageCache($pool);
        $firstBuilds = 0;
        $secondBuilds = 0;
        $firstFactory = static function () use (&$firstBuilds): Response {
            ++$firstBuilds;

            return new Response('first-content-' . $firstBuilds);
        };
        $secondFactory = static function () use (&$secondBuilds): Response {
            ++$secondBuilds;

            return new Response('second-content-' . $secondBuilds);
        };

        self::assertSame('first-content-1', $cache->contentResponse('full_bot', '/one', $firstFactory)->getContent());
        $cache->rememberContentPath(ContentId::post(1), '/one');
        self::assertSame('first-content-1', $cache->contentResponse('full_bot', '/one', $firstFactory)->getContent());

        self::assertSame('second-content-1', $cache->contentResponse('full_bot', '/two', $secondFactory)->getContent());
        $cache->rememberContentPath(ContentId::post(2), '/two');
        self::assertSame('second-content-1', $cache->contentResponse('full_bot', '/two', $secondFactory)->getContent());
        self::assertCount(2, $this->contentResponseKeys($pool));

        $cache->invalidateContent(ContentId::post(1));
        self::assertSame('first-content-2', $cache->contentResponse('full_bot', '/one', $firstFactory)->getContent());
        self::assertSame('second-content-1', $cache->contentResponse('full_bot', '/two', $secondFactory)->getContent());

        $cache->invalidateContentResponses();
        self::assertSame('first-content-3', $cache->contentResponse('full_bot', '/one', $firstFactory)->getContent());
        self::assertSame('second-content-2', $cache->contentResponse('full_bot', '/two', $secondFactory)->getContent());
        self::assertCount(2, $this->contentResponseKeys($pool));
    }

    public function testDisabledCacheAlwaysBuildsFreshFragments(): void
    {
        $cache  = new BlogPageCache(new ArrayAdapter(), true);
        $builds = 0;
        $factory = static function () use (&$builds): PostFeed {
            ++$builds;

            return new PostFeed('feed-' . $builds, null, null);
        };

        self::assertSame('feed-1', $cache->firstPage($factory)->html);
        self::assertSame('feed-2', $cache->firstPage($factory)->html);
        self::assertSame(2, $builds);
    }

    public function testOnlyBoundedHotEntriesReachTheMemoryTier(): void
    {
        $memory = new ArrayAdapter();
        $filesystem = new ArrayAdapter();
        $hot = new ChainAdapter([$memory, $filesystem]);
        $cache = new BlogPageCache($filesystem, false, $hot);

        $cache->firstPage(static fn(): PostFeed => new PostFeed('hot feed', null, null));
        self::assertTrue($memory->hasItem('register_blog_first_page_v1'));
        self::assertTrue($filesystem->hasItem('register_blog_first_page_v1'));

        $cache->contentResponse(
            'full_bot',
            '/cold-content',
            static fn(): Response => new Response('cold response'),
        );
        $memoryKeys = array_keys($memory->getValues());
        self::assertContains('register_content_response_generation_v1', $memoryKeys);
        self::assertSame([], array_values(array_filter(
            $memoryKeys,
            static fn(string $key): bool => str_starts_with($key, 'register_content_response_v2_'),
        )));

        $cache->invalidateFirstPage();
        self::assertFalse($memory->hasItem('register_blog_first_page_v1'));
        self::assertFalse($filesystem->hasItem('register_blog_first_page_v1'));

        $cache->invalidateContentResponses();
        self::assertFalse($memory->hasItem('register_content_response_generation_v1'));
        self::assertFalse($filesystem->hasItem('register_content_response_generation_v1'));
    }

    public function testCommitDeletesAStaleValueRepopulatedDuringTheTransaction(): void
    {
        $pool = new ArrayAdapter();
        $pdo = new PDO('sqlite::memory:');
        $cache = new BlogPageCache($pool, false, null, $pdo);
        $builds = 0;
        $factory = static function () use (&$builds): PostFeed {
            ++$builds;

            return new PostFeed('feed-' . $builds, null, null);
        };

        self::assertSame('feed-1', $cache->firstPage($factory)->html);

        $pdo->beginTransaction();
        $cache->invalidateFirstPage();
        self::assertSame('feed-2', $cache->firstPage($factory)->html);
        $pdo->commit();

        self::assertSame('feed-3', $cache->firstPage($factory)->html);
    }

    public function testRepeatedInvalidationDeletesIntermediateValuesWithinOneTransaction(): void
    {
        $pool = new ArrayAdapter();
        $pdo = new PDO('sqlite::memory:');
        $cache = new BlogPageCache($pool, false, null, $pdo);
        $builds = 0;
        $factory = static function () use (&$builds): PostFeed {
            ++$builds;

            return new PostFeed('feed-' . $builds, null, null);
        };

        self::assertSame('feed-1', $cache->firstPage($factory)->html);

        $pdo->beginTransaction();
        $cache->invalidateFirstPage();
        self::assertSame('feed-2', $cache->firstPage($factory)->html);
        $cache->invalidateFirstPage();
        self::assertSame('feed-3', $cache->firstPage($factory)->html);
        $pdo->commit();

        self::assertSame('feed-4', $cache->firstPage($factory)->html);
    }

    public function testRollbackDeletesAValueBuiltFromUncommittedRows(): void
    {
        $pool = new ArrayAdapter();
        $pdo = new PDO('sqlite::memory:');
        $cache = new BlogPageCache($pool, false, null, $pdo);
        $builds = 0;
        $factory = static function () use (&$builds): PostFeed {
            ++$builds;

            return new PostFeed('feed-' . $builds, null, null);
        };

        self::assertSame('feed-1', $cache->firstPage($factory)->html);
        $pdo->beginTransaction();
        $cache->invalidateFirstPage();
        self::assertSame('feed-2', $cache->firstPage($factory)->html);
        $pdo->rollBack();

        self::assertSame('feed-3', $cache->firstPage($factory)->html);
    }

    /** @return list<string> */
    private function contentResponseKeys(ArrayAdapter $pool): array
    {
        $result = [];
        foreach (array_keys($pool->getValues()) as $key) {
            if (\is_string($key) && str_starts_with($key, 'register_content_response_v2_')) {
                $result[] = $key;
            }
        }

        return $result;
    }
}

final class BlogPageCacheTestClock
{
    public function __construct(public int $now)
    {
    }

    public function __invoke(): int
    {
        return $this->now;
    }
}
