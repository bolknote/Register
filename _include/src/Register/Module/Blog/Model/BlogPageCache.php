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
use Register\Core\Http\Cache\PageCacheHeaders;
use Register\Core\Pdo\PDO;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Stores deterministic blog fragments and complete anonymous page representations. */
final class BlogPageCache implements StatefulServiceInterface
{
    private const string FIRST_PAGE_KEY = 'register_blog_first_page_v1';

    private const string ALL_POSTS_KEY = 'register_blog_all_posts_v1';

    private const string MULTIPLE_PUBLISHED_AUTHORS_KEY = 'register_blog_multiple_published_authors_v1';

    private const string NAVIGATION_KEY = 'register_blog_navigation_v2';

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

    private readonly CacheInterface $hotCache;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private bool $buildingResponse = false;

    private ?int $currentResponseInvalidationAt = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly bool           $disabled = false,
        ?CacheInterface                  $hotCache = null,
        private readonly ?PDO            $pdo = null,
        ?\Closure                        $clock = null,
    ) {
        $this->hotCache = $hotCache ?? $cache;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @param callable(): PostFeed $factory */
    public function firstPage(callable $factory): PostFeed
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::FIRST_PAGE_KEY,
            static fn(ItemInterface $_item): PostFeed => $factory(),
            0.0,
        );
    }

    /** @param callable(): AllPostsPage $factory */
    public function allPosts(callable $factory): AllPostsPage
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::ALL_POSTS_KEY,
            static fn(ItemInterface $_item): AllPostsPage => $factory(),
            0.0,
        );
    }

    /** @param callable(): bool $factory */
    public function multiplePublishedAuthors(callable $factory): bool
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::MULTIPLE_PUBLISHED_AUTHORS_KEY,
            static fn(ItemInterface $_item): bool => $factory(),
            0.0,
        );
    }

    /**
     * @param callable(): array<mixed> $factory
     * @return array<mixed>
     */
    public function navigation(callable $factory): array
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::NAVIGATION_KEY,
            static fn(ItemInterface $_item): array => $factory(),
            0.0,
        );
    }

    /** @param callable(): Response $factory */
    public function firstResponse(string $variant, callable $factory): Response
    {
        return $this->response(
            $this->hotCache,
            self::FIRST_RESPONSE_PREFIX . $this->validatedVariant($variant),
            $factory,
        );
    }

    /** @param callable(): Response $factory */
    public function allResponse(string $variant, callable $factory): Response
    {
        return $this->response(
            $this->hotCache,
            self::ALL_RESPONSE_PREFIX . $this->validatedVariant($variant),
            $factory,
        );
    }

    /** @param callable(): Response $factory */
    public function contentResponse(string $variant, string $path, callable $factory): Response
    {
        $path = $this->normalizedContentPath($path);

        return $this->response(
            $this->cache,
            $this->contentResponsePrefix($path) . $this->validatedVariant($variant),
            $factory,
            $this->contentResponseGeneration(),
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
        $this->cache->get(
            $mappingKey,
            static fn(ItemInterface $_item): array => $paths,
            0.0,
        );
    }

    public function invalidateContent(ContentId $contentId): void
    {
        if ($this->disabled) {
            return;
        }

        $this->afterCommitOnce('content:' . (string)$contentId, function () use ($contentId): void {
            $mappingKey = $this->contentPathKey($contentId);
            $paths = $this->cache->get(
                $mappingKey,
                $this->missingContentPaths(...),
                0.0,
            );
            foreach ($paths as $path) {
                $this->deleteResponses(
                    $this->cache,
                    $this->contentResponsePrefix($this->normalizedContentPath($path)),
                );
            }

            $this->cache->delete($mappingKey);
        });
    }

    public function invalidateContentResponses(): void
    {
        if ($this->disabled) {
            return;
        }

        $this->afterCommitOnce(
            'content-responses',
            function (): void {
                $this->hotCache->delete(self::CONTENT_RESPONSE_GENERATION_KEY);
            },
        );
    }

    public function invalidateFirstPage(): void
    {
        if ($this->disabled) {
            return;
        }

        $this->afterCommitOnce('first-page', function (): void {
            $this->hotCache->delete(self::FIRST_PAGE_KEY);
            $this->deleteResponses($this->hotCache, self::FIRST_RESPONSE_PREFIX);
        });
    }

    public function invalidateAll(): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateFirstPage();
        $this->afterCommitOnce('all-posts', function (): void {
            $this->hotCache->delete(self::ALL_POSTS_KEY);
            $this->deleteResponses($this->hotCache, self::ALL_RESPONSE_PREFIX);
        });
        $this->afterCommitOnce('published-authors', function (): void {
            $this->hotCache->delete(self::MULTIPLE_PUBLISHED_AUTHORS_KEY);
        });
        $this->afterCommitOnce('navigation', function (): void {
            $this->hotCache->delete(self::NAVIGATION_KEY);
        });
        $this->invalidateContentResponses();
    }

    /**
     * Marks the exact instant when a clock-dependent fragment changes meaning.
     * This is a semantic dependency boundary, not a cache lifetime.
     */
    public function invalidateCurrentResponseAt(int $timestamp): void
    {
        if (!$this->buildingResponse || $timestamp < 1) {
            return;
        }

        $this->currentResponseInvalidationAt = $this->currentResponseInvalidationAt === null
            ? $timestamp
            : min($this->currentResponseInvalidationAt, $timestamp);
    }

    #[\Override]
    public function clearState(): void
    {
        // Transaction-level coalescing is owned by PDO and is reset on commit or rollback.
        $this->buildingResponse = false;
        $this->currentResponseInvalidationAt = null;
    }

    /** @param callable(): Response $factory */
    private function response(
        CacheInterface $cache,
        string $key,
        callable $factory,
        ?string $dependencyVersion = null,
    ): Response
    {
        if ($this->disabled) {
            return $factory();
        }

        $miss = false;
        $factory = function (ItemInterface $_item, bool &$save) use (
            $factory,
            $dependencyVersion,
            &$miss,
        ): CachedBlogResponse|Response {
            $miss = true;
            $this->buildingResponse = true;
            $this->currentResponseInvalidationAt = null;
            try {
                $response = $factory();
                $validUntil = $this->currentResponseInvalidationAt;
            } finally {
                $this->buildingResponse = false;
                $this->currentResponseInvalidationAt = null;
            }

            $cached = CachedBlogResponse::fromResponse($response, $dependencyVersion, $validUntil);
            if (!$cached instanceof CachedBlogResponse) {
                $save = false;

                return $response;
            }

            return $cached;
        };
        $value = $cache->get($key, $factory, 0.0);
        if ($value instanceof CachedBlogResponse && (
            !$value->matchesDependencyVersion($dependencyVersion)
            || !$value->isFreshAt(($this->clock)())
        )) {
            // Keep one stable slot per route. A dependency event changes the
            // version stored inside that slot, so stale generations cannot pile up.
            $cache->delete($key);
            $value = $cache->get($key, $factory, 0.0);
        }

        $response = $value instanceof CachedBlogResponse ? $value->toResponse() : $value;
        $response->headers->set(PageCacheHeaders::STATUS, $miss ? 'miss' : 'hit');
        $response->headers->set(PageCacheHeaders::IDENTITY, hash('sha256', $key));

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
            . hash('sha256', $path)
            . '_';
    }

    private function contentResponseGeneration(): string
    {
        $generation = $this->hotCache->get(
            self::CONTENT_RESPONSE_GENERATION_KEY,
            static fn(ItemInterface $_item): string => bin2hex(random_bytes(8)),
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

    private function deleteResponses(CacheInterface $cache, string $prefix): void
    {
        foreach (self::RESPONSE_VARIANTS as $variant) {
            $cache->delete($prefix . $variant);
        }
    }

    /** @param \Closure(): mixed $operation */
    private function afterCommitOnce(string $key, \Closure $operation): void
    {
        if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
            $callbackKey = 'blog-page-cache:' . $key;
            if ($this->pdo->afterCommitOnce($callbackKey, $operation)) {
                // Delete once now and once after completion. The second delete closes
                // the race where another connection repopulates old committed data
                // before this transaction finishes. Rollback also removes anything
                // the mutating connection could have rebuilt from uncommitted rows.
                $this->pdo->afterRollbackOnce($callbackKey, $operation);
                $operation();
            }

            return;
        }

        $operation();
    }
}
