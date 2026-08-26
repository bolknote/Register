<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Stores only deterministic blog fragments; request-specific chrome is still rendered every time. */
final readonly class BlogPageCache
{
    private const string FIRST_PAGE_KEY = 'register_blog_first_page_v1';

    private const string ALL_POSTS_KEY = 'register_blog_all_posts_v1';

    /** View counters may change without a content event, so keep their maximum staleness bounded. */
    private const int FIRST_PAGE_TTL_SECONDS = 300;

    /** Content events invalidate this entry; the TTL is a fallback for out-of-band database changes. */
    private const int ALL_POSTS_TTL_SECONDS = 86400;

    public function __construct(
        private CacheInterface $cache,
        private bool           $disabled = false,
    ) {
    }

    /** @param callable(): PostFeed $factory */
    public function firstPage(callable $factory): PostFeed
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->cache->get(self::FIRST_PAGE_KEY, static function (ItemInterface $item) use ($factory): PostFeed {
            $item->expiresAfter(self::FIRST_PAGE_TTL_SECONDS);

            return $factory();
        });
    }

    /** @param callable(): AllPostsPage $factory */
    public function allPosts(callable $factory): AllPostsPage
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->cache->get(self::ALL_POSTS_KEY, static function (ItemInterface $item) use ($factory): AllPostsPage {
            $item->expiresAfter(self::ALL_POSTS_TTL_SECONDS);

            return $factory();
        });
    }

    public function invalidateFirstPage(): void
    {
        if (!$this->disabled) {
            $this->cache->delete(self::FIRST_PAGE_KEY);
        }
    }

    public function invalidateAll(): void
    {
        if ($this->disabled) {
            return;
        }

        $this->cache->delete(self::FIRST_PAGE_KEY);
        $this->cache->delete(self::ALL_POSTS_KEY);
    }
}
