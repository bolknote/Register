<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Core\Framework\StatefulServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Stores deterministic blog fragments and complete anonymous page representations. */
final class BlogPageCache implements StatefulServiceInterface
{
    private const string FIRST_PAGE_KEY = 'register_blog_first_page_v1';

    private const string ALL_POSTS_KEY = 'register_blog_all_posts_v1';

    private const string MULTIPLE_PUBLISHED_AUTHORS_KEY = 'register_blog_multiple_published_authors_v1';

    private const string FIRST_RESPONSE_PREFIX = 'register_blog_first_response_v2_';

    private const string ALL_RESPONSE_PREFIX = 'register_blog_all_response_v2_';

    private const string CONTENT_RESPONSE_GENERATION_KEY = 'register_content_response_generation_v1';

    private const string CONTENT_RESPONSE_PATH_PREFIX = 'register_content_response_path_v1_';

    private const string CONTENT_RESPONSE_PREFIX = 'register_content_response_v2_';

    private const array RESPONSE_VARIANTS = [
        'full_bot',
        'full_new_visitor',
        'full_known_visitor',
        'partial_bot',
        'partial_new_visitor',
        'partial_known_visitor',
    ];

    /** View counters may change without a content event, so keep their maximum staleness bounded. */
    private const int FIRST_PAGE_TTL_SECONDS = 300;

    /** Content events invalidate this entry; the TTL is a fallback for out-of-band database changes. */
    private const int ALL_POSTS_TTL_SECONDS = 86400;

    /** Request-bound forms are hydrated after this shared content response leaves the cache. */
    private const int CONTENT_RESPONSE_TTL_SECONDS = 86400;

    private bool $firstPageInvalidated = false;

    private bool $allPostsInvalidated = false;

    private bool $publishedAuthorsInvalidated = false;

    private bool $contentResponsesInvalidated = false;

    /** @var array<string, true> */
    private array $invalidatedContent = [];

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

    /** @param callable(): bool $factory */
    public function multiplePublishedAuthors(callable $factory): bool
    {
        if ($this->disabled) {
            return $factory();
        }

        $multiple = $this->cache->get(
            self::MULTIPLE_PUBLISHED_AUTHORS_KEY,
            static function (ItemInterface $item) use ($factory): bool {
                $item->expiresAfter(self::ALL_POSTS_TTL_SECONDS);

                return $factory();
            },
            0.0,
        );
        $this->publishedAuthorsInvalidated = false;

        return $multiple;
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
        // Content events invalidate the deterministic archive response; the TTL covers out-of-band changes.
        $response = $this->response(
            self::ALL_RESPONSE_PREFIX . $this->validatedVariant($variant),
            self::ALL_POSTS_TTL_SECONDS,
            $factory,
        );
        $this->allPostsInvalidated = false;

        return $response;
    }

    /** @param callable(): Response $factory */
    public function contentResponse(string $variant, string $path, callable $factory): Response
    {
        $path = $this->normalizedContentPath($path);

        return $this->response(
            $this->contentResponsePrefix($path) . $this->validatedVariant($variant),
            self::CONTENT_RESPONSE_TTL_SECONDS,
            $factory,
        );
    }

    public function rememberContentPath(ContentId $contentId, string $path): void
    {
        if ($this->disabled) {
            return;
        }

        $path = $this->normalizedContentPath($path);
        $mappingKey = $this->contentPathKey($contentId);
        $paths = $this->cache->get(
            $mappingKey,
            $this->missingContentPaths(...),
            0.0,
        );
        if (\in_array($path, $paths, true)) {
            return;
        }

        $paths[] = $path;
        $paths = array_slice($paths, -4);
        $this->cache->delete($mappingKey);
        $this->cache->get($mappingKey, static function (ItemInterface $item) use ($paths): array {
            $item->expiresAfter(self::CONTENT_RESPONSE_TTL_SECONDS);

            return $paths;
        }, 0.0);
    }

    public function invalidateContent(ContentId $contentId): void
    {
        if ($this->disabled || isset($this->invalidatedContent[(string)$contentId])) {
            return;
        }

        $mappingKey = $this->contentPathKey($contentId);
        $paths = $this->cache->get(
            $mappingKey,
            $this->missingContentPaths(...),
            0.0,
        );
        foreach ($paths as $path) {
            $this->deleteResponses($this->contentResponsePrefix($this->normalizedContentPath($path)));
        }

        $this->cache->delete($mappingKey);
        $this->invalidatedContent[(string)$contentId] = true;
    }

    public function invalidateContentResponses(): void
    {
        if ($this->disabled || $this->contentResponsesInvalidated) {
            return;
        }

        $this->cache->delete(self::CONTENT_RESPONSE_GENERATION_KEY);
        $this->contentResponsesInvalidated = true;
        $this->invalidatedContent = [];
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
        if ($this->disabled || (
            $this->firstPageInvalidated
            && $this->allPostsInvalidated
            && $this->publishedAuthorsInvalidated
        )) {
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

        if (!$this->publishedAuthorsInvalidated) {
            $this->cache->delete(self::MULTIPLE_PUBLISHED_AUTHORS_KEY);
            $this->publishedAuthorsInvalidated = true;
        }
    }

    #[\Override]
    public function clearState(): void
    {
        $this->firstPageInvalidated = false;
        $this->allPostsInvalidated  = false;
        $this->publishedAuthorsInvalidated = false;
        $this->contentResponsesInvalidated = false;
        $this->invalidatedContent = [];
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

    private function contentPathKey(ContentId $contentId): string
    {
        return self::CONTENT_RESPONSE_PATH_PREFIX . hash('sha256', (string)$contentId);
    }

    private function contentResponsePrefix(string $path): string
    {
        return self::CONTENT_RESPONSE_PREFIX
            . $this->contentResponseGeneration()
            . '_'
            . hash('sha256', $path)
            . '_';
    }

    private function contentResponseGeneration(): string
    {
        $generation = $this->cache->get(
            self::CONTENT_RESPONSE_GENERATION_KEY,
            static function (ItemInterface $item): string {
                $item->expiresAfter(self::ALL_POSTS_TTL_SECONDS);

                return bin2hex(random_bytes(8));
            },
            0.0,
        );
        if (preg_match('/^[a-f0-9]{16}$/D', $generation) !== 1) {
            throw new \UnexpectedValueException('The content response cache generation is invalid.');
        }

        return $generation;
    }

    private function normalizedContentPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '?')) {
            throw new \InvalidArgumentException('A cached content path must be an absolute path without a query.');
        }

        return implode('/', array_map(
            static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
    }

    /** @return list<string> */
    private function missingContentPaths(ItemInterface $item, bool &$save): array
    {
        if ($item->isHit()) {
            throw new \LogicException('A missing content-path callback unexpectedly received a cache hit.');
        }

        $save = false;

        return [];
    }

    private function deleteResponses(string $prefix): void
    {
        foreach (self::RESPONSE_VARIANTS as $variant) {
            $this->cache->delete($prefix . $variant);
        }
    }
}
