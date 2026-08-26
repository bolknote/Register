<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Blog\Model;

use PHPUnit\Framework\TestCase;
use Register\Module\Blog\Model\AllPostsPage;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\PostFeed;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BlogPageCacheTest extends TestCase
{
    public function testFragmentsAreReusedUntilTheirRelevantInvalidation(): void
    {
        $cache = new BlogPageCache(new ArrayAdapter());
        $firstPageBuilds = 0;
        $allPostsBuilds  = 0;

        $firstFactory = static function () use (&$firstPageBuilds): PostFeed {
            ++$firstPageBuilds;

            return new PostFeed('feed-' . $firstPageBuilds, null, '/skip/20');
        };
        $allFactory = static function () use (&$allPostsBuilds): AllPostsPage {
            ++$allPostsBuilds;

            return new AllPostsPage('All ' . $allPostsBuilds, 'index-' . $allPostsBuilds);
        };

        self::assertSame('feed-1', $cache->firstPage($firstFactory)->html);
        self::assertSame('feed-1', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);
        self::assertSame(1, $firstPageBuilds);
        self::assertSame(1, $allPostsBuilds);

        $cache->invalidateFirstPage();
        self::assertSame('feed-2', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-1', $cache->allPosts($allFactory)->html);

        $cache->invalidateAll();
        self::assertSame('feed-3', $cache->firstPage($firstFactory)->html);
        self::assertSame('index-2', $cache->allPosts($allFactory)->html);
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
}
