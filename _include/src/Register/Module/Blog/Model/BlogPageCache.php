<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Core\Framework\StatefulServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Stores deterministic blog fragments and complete anonymous page representations. */
final class BlogPageCache implements StatefulServiceInterface
{
    private const string FIRST_PAGE_KEY = 'register_blog_first_page_v1';

    private const string ALL_POSTS_KEY = 'register_blog_all_posts_v1';

    private const string FIRST_RESPONSE_PREFIX = 'register_blog_first_response_v2_';

    private const string ALL_RESPONSE_PREFIX = 'register_blog_all_response_v2_';

    private const array RESPONSE_VARIANTS = [
        'full_new_visitor',
        'full_known_visitor',
        'partial_new_visitor',
        'partial_known_visitor',
    ];

    /** View counters may change without a content event, so keep their maximum staleness bounded. */
    private const int FIRST_PAGE_TTL_SECONDS = 300;

    /** Content events invalidate this entry; the TTL is a fallback for out-of-band database changes. */
    private const int ALL_POSTS_TTL_SECONDS = 86400;

    private bool $firstPageInvalidated = false;

    private bool $allPostsInvalidated = false;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly bool           $disabled = false,
    ) {
    }

    /** @param callable(): PostFeed $factory */
    public function firstPage(callable $factory): PostFeed
    {
        if ($this->disabled) {
            return $factory();
        }

        $feed = $this->cache->get(self::FIRST_PAGE_KEY, static function (ItemInterface $item) use ($factory): PostFeed {
            $item->expiresAfter(self::FIRST_PAGE_TTL_SECONDS);

            return $factory();
        }, 0.0);
        $this->firstPageInvalidated = false;

        return $feed;
    }

    /** @param callable(): AllPostsPage $factory */
    public function allPosts(callable $factory): AllPostsPage
    {
        if ($this->disabled) {
            return $factory();
        }

        $page = $this->cache->get(self::ALL_POSTS_KEY, static function (ItemInterface $item) use ($factory): AllPostsPage {
            $item->expiresAfter(self::ALL_POSTS_TTL_SECONDS);

            return $factory();
        }, 0.0);
        $this->allPostsInvalidated = false;

        return $page;
    }

    /** @param callable(): Response $factory */
    public function firstResponse(string $variant, callable $factory): Response
    {
        $response = $this->response(
            self::FIRST_RESPONSE_PREFIX . $this->validatedVariant($variant),
            self::FIRST_PAGE_TTL_SECONDS,
            $factory,
        );
        $this->firstPageInvalidated = false;

        return $response;
    }

    /** @param callable(): Response $factory */
    public function allResponse(string $variant, callable $factory): Response
    {
        // The response contains an hourly guest form token. The expensive archive fragment has its own daily TTL.
        $response = $this->response(
            self::ALL_RESPONSE_PREFIX . $this->validatedVariant($variant),
            self::FIRST_PAGE_TTL_SECONDS,
            $factory,
        );
        $this->allPostsInvalidated = false;

        return $response;
    }

    public function invalidateFirstPage(): void
    {
        if ($this->disabled || $this->firstPageInvalidated) {
            return;
        }

        $this->cache->delete(self::FIRST_PAGE_KEY);
        $this->deleteResponses(self::FIRST_RESPONSE_PREFIX);
        $this->firstPageInvalidated = true;
    }

    public function invalidateAll(): void
    {
        if ($this->disabled || ($this->firstPageInvalidated && $this->allPostsInvalidated)) {
            return;
        }

        if (!$this->firstPageInvalidated) {
            $this->cache->delete(self::FIRST_PAGE_KEY);
            $this->deleteResponses(self::FIRST_RESPONSE_PREFIX);
            $this->firstPageInvalidated = true;
        }

        if (!$this->allPostsInvalidated) {
            $this->cache->delete(self::ALL_POSTS_KEY);
            $this->deleteResponses(self::ALL_RESPONSE_PREFIX);
            $this->allPostsInvalidated = true;
        }
    }

    #[\Override]
    public function clearState(): void
    {
        $this->firstPageInvalidated = false;
        $this->allPostsInvalidated  = false;
    }

    /** @param callable(): Response $factory */
    private function response(string $key, int $ttl, callable $factory): Response
    {
        if ($this->disabled) {
            return $factory();
        }

        $miss = false;
        $value = $this->cache->get(
            $key,
            static function (ItemInterface $item, bool &$save) use ($factory, $ttl, &$miss): CachedBlogResponse|Response {
                $miss = true;
                $item->expiresAfter($ttl);
                $response = $factory();
                $cached = CachedBlogResponse::fromResponse($response);
                if (!$cached instanceof CachedBlogResponse) {
                    $save = false;

                    return $response;
                }

                return $cached;
            },
            0.0,
        );

        $response = $value instanceof CachedBlogResponse ? $value->toResponse() : $value;
        $response->headers->set('X-Register-Page-Cache', $miss ? 'miss' : 'hit');

        return $response;
    }

    private function validatedVariant(string $variant): string
    {
        if (!\in_array($variant, self::RESPONSE_VARIANTS, true)) {
            throw new \InvalidArgumentException('Unknown blog response cache variant.');
        }

        return $variant;
    }

    private function deleteResponses(string $prefix): void
    {
        foreach (self::RESPONSE_VARIANTS as $variant) {
            $this->cache->delete($prefix . $variant);
        }
    }
}
