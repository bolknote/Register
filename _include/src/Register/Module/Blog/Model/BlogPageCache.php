<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Content\ContentType;
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

    private const string LAST_POST_KEY = 'register_blog_last_post_v1';

    private const string POST_PAGE_CONTEXT_KEY = 'register_blog_post_page_context_v1';

    private const string RECENT_COMMENTS_KEY = 'register_blog_recent_comments_v1';

    private const string RECENT_DISCUSSIONS_KEY = 'register_blog_recent_discussions_v1';

    private const string FIRST_RESPONSE_PREFIX = 'register_blog_first_response_v2_';

    private const string ALL_RESPONSE_PREFIX = 'register_blog_all_response_v2_';

    private const string CONTENT_RESPONSE_GENERATION_KEY = 'register_content_response_generation_v1';

    private const string CONTENT_RESPONSE_TYPE_GENERATION_PREFIX = 'register_content_response_type_generation_v1_';

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
        $this->clock = $clock ?? time(...);
    }

    /** @param callable(): PostFeed $factory */
    public function firstPage(callable $factory): PostFeed
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::FIRST_PAGE_KEY,
            static function (ItemInterface $item) use ($factory): PostFeed {
                $item->expiresAfter(null);

                return $factory();
            },
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
            static function (ItemInterface $item) use ($factory): AllPostsPage {
                $item->expiresAfter(null);

                return $factory();
            },
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
            static function (ItemInterface $item) use ($factory): bool {
                $item->expiresAfter(null);

                return $factory();
            },
            0.0,
        );
    }

    /** @return array<mixed> */
    public function navigation(callable $factory): array
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::NAVIGATION_KEY,
            static function (ItemInterface $item) use ($factory): array {
                $item->expiresAfter(null);

                return $factory();
            },
            0.0,
        );
    }

    /** @param callable(): string $factory */
    public function lastPost(callable $factory): string
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::LAST_POST_KEY,
            static function (ItemInterface $item) use ($factory): string {
                $item->expiresAfter(null);

                return $factory();
            },
            0.0,
        );
    }

    /** @param callable(): PostPageContextIndex $factory */
    public function postPageContextIndex(callable $factory): PostPageContextIndex
    {
        if ($this->disabled) {
            return $factory();
        }

        return $this->hotCache->get(
            self::POST_PAGE_CONTEXT_KEY,
            static function (ItemInterface $item) use ($factory): PostPageContextIndex {
                $item->expiresAfter(null);

                return $factory();
            },
            0.0,
        );
    }

    /**
     * @param callable(): BlogSidebarFeed $factory
     * @return list<array<mixed>>
     */
    public function recentComments(callable $factory): array
    {
        return $this->sidebarFeed(self::RECENT_COMMENTS_KEY, $factory)->items;
    }

    /**
     * @param callable(): BlogSidebarFeed $factory
     * @return list<array<mixed>>
     */
    public function recentDiscussions(callable $factory): array
    {
        return $this->sidebarFeed(self::RECENT_DISCUSSIONS_KEY, $factory)->items;
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
            contentScoped: true,
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
            static function (ItemInterface $item) use ($paths): array {
                $item->expiresAfter(null);

                return $paths;
            },
            0.0,
        );
    }

    public function invalidateContent(ContentId $contentId, bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce('content:' . (string)$contentId, function () use ($contentId): void {
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
        }, $deferUntilCommit);
    }

    public function invalidateContentResponses(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce(
            'content-responses',
            function (): void {
                $this->hotCache->delete(self::CONTENT_RESPONSE_GENERATION_KEY);
            },
            $deferUntilCommit,
        );
    }

    public function invalidateContentTypeResponses(ContentType $contentType, bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce(
            'content-responses:' . $contentType->value,
            function () use ($contentType): void {
                $this->hotCache->delete(self::CONTENT_RESPONSE_TYPE_GENERATION_PREFIX . $contentType->value);
            },
            $deferUntilCommit,
        );
    }

    public function invalidateFirstPage(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce('first-page', function (): void {
            $this->hotCache->delete(self::FIRST_PAGE_KEY);
            $this->deleteResponses($this->hotCache, self::FIRST_RESPONSE_PREFIX);
        }, $deferUntilCommit);
    }

    public function invalidateCommentFragments(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce('comment-fragments', function (): void {
            $this->hotCache->delete(self::RECENT_COMMENTS_KEY);
            $this->hotCache->delete(self::RECENT_DISCUSSIONS_KEY);
        }, $deferUntilCommit);
    }

    public function invalidateCommentChange(ContentId $contentId, bool $deferUntilCommit = false): void
    {
        $this->invalidateContent($contentId, $deferUntilCommit);
        if ($contentId->type !== ContentType::POST) {
            return;
        }

        // The first feed contains comment counters. Site-wide comment menus are
        // hydrated independently after a complete page-cache hit.
        $this->invalidateFirstPage($deferUntilCommit);
        $this->invalidateLastPost($deferUntilCommit);
        $this->invalidateCommentFragments($deferUntilCommit);
    }

    /**
     * Invalidates the changed document and independently cached projections of it.
     *
     * Complete responses for unrelated posts stay warm: site-wide projections are
     * hydrated after the response cache and have their own event-driven entries.
     */
    public function invalidateContentChange(ContentId $contentId, bool $deferUntilCommit = false): void
    {
        $this->invalidateContent($contentId, $deferUntilCommit);
        if ($contentId->type !== ContentType::POST) {
            // Page hierarchy menus are cross-page projections. Rotate only page
            // responses; thousands of unrelated post responses remain warm.
            $this->invalidateContentTypeResponses(ContentType::PAGE, $deferUntilCommit);

            return;
        }

        // Article pages can contain projections derived from the blog post set
        // (for example, related blog tags). Rotate only those page shells; post
        // shells use independently hydrated projections and stay warm.
        $this->invalidateContentTypeResponses(ContentType::PAGE, $deferUntilCommit);
        $this->invalidateFirstPage($deferUntilCommit);
        $this->invalidatePostListings($deferUntilCommit);
        $this->invalidatePublishedAuthors($deferUntilCommit);
        $this->invalidateNavigation($deferUntilCommit);
        $this->invalidateLastPost($deferUntilCommit);
        $this->invalidatePostPageContext($deferUntilCommit);
        $this->invalidateCommentFragments($deferUntilCommit);
    }

    public function invalidateLastPost(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce('last-post', function (): void {
            $this->hotCache->delete(self::LAST_POST_KEY);
        }, $deferUntilCommit);
    }

    public function invalidatePostPageContext(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateOnce('post-page-context', function (): void {
            $this->hotCache->delete(self::POST_PAGE_CONTEXT_KEY);
        }, $deferUntilCommit);
    }

    public function invalidateAll(bool $deferUntilCommit = false): void
    {
        if ($this->disabled) {
            return;
        }

        $this->invalidateFirstPage($deferUntilCommit);
        $this->invalidatePostListings($deferUntilCommit);
        $this->invalidatePublishedAuthors($deferUntilCommit);
        $this->invalidateNavigation($deferUntilCommit);
        $this->invalidateLastPost($deferUntilCommit);
        $this->invalidatePostPageContext($deferUntilCommit);
        $this->invalidateCommentFragments($deferUntilCommit);
        $this->invalidateContentResponses($deferUntilCommit);
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
        bool $contentScoped = false,
    ): Response
    {
        if ($this->disabled) {
            return $factory();
        }

        $miss = false;
        $factory = function (ItemInterface $_item, bool &$save) use (
            $factory,
            $dependencyVersion,
            $contentScoped,
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

            $dependencyScope = $contentScoped ? $this->contentResponseScope($response) : null;
            $resolvedDependencyVersion = $contentScoped
                ? $this->contentResponseGeneration($dependencyScope)
                : $dependencyVersion;
            $cached = CachedBlogResponse::fromResponse(
                $response,
                $resolvedDependencyVersion,
                $validUntil,
                $dependencyScope?->value,
            );
            if (!$cached instanceof CachedBlogResponse) {
                $save = false;

                return $response;
            }

            return $cached;
        };
        $value = $cache->get($key, $factory, 0.0);
        $resolvedDependencyVersion = $contentScoped && $value instanceof CachedBlogResponse
            ? $this->contentResponseGeneration($this->contentTypeFromScope($value->dependencyScope()))
            : $dependencyVersion;
        if ($value instanceof CachedBlogResponse && (
            !$value->matchesDependencyVersion($resolvedDependencyVersion)
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

    /** @param callable(): BlogSidebarFeed $factory */
    private function sidebarFeed(string $key, callable $factory): BlogSidebarFeed
    {
        if ($this->disabled) {
            return $factory();
        }

        $build = static function (ItemInterface $item) use ($factory): BlogSidebarFeed {
            $item->expiresAfter(null);

            return $factory();
        };
        $feed = $this->hotCache->get($key, $build, 0.0);

        if (!$feed->isFreshAt(($this->clock)())) {
            $this->hotCache->delete($key);
            $feed = $this->hotCache->get($key, $build, 0.0);
        }

        return $feed;
    }

    private function invalidatePostListings(bool $deferUntilCommit): void
    {
        $this->invalidateOnce('all-posts', function (): void {
            $this->hotCache->delete(self::ALL_POSTS_KEY);
            $this->deleteResponses($this->hotCache, self::ALL_RESPONSE_PREFIX);
        }, $deferUntilCommit);
    }

    private function invalidatePublishedAuthors(bool $deferUntilCommit): void
    {
        $this->invalidateOnce('published-authors', function (): void {
            $this->hotCache->delete(self::MULTIPLE_PUBLISHED_AUTHORS_KEY);
        }, $deferUntilCommit);
    }

    private function invalidateNavigation(bool $deferUntilCommit): void
    {
        $this->invalidateOnce('navigation', function (): void {
            $this->hotCache->delete(self::NAVIGATION_KEY);
        }, $deferUntilCommit);
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

    private function contentResponseGeneration(?ContentType $contentType = null): string
    {
        $generation = $this->generation(self::CONTENT_RESPONSE_GENERATION_KEY);
        if (!$contentType instanceof ContentType) {
            return $generation;
        }

        return $generation . ':' . $this->generation(
            self::CONTENT_RESPONSE_TYPE_GENERATION_PREFIX . $contentType->value,
        );
    }

    private function generation(string $key): string
    {
        $generation = $this->hotCache->get(
            $key,
            static function (ItemInterface $item): string {
                $item->expiresAfter(null);

                return bin2hex(random_bytes(8));
            },
            0.0,
        );
        if (preg_match('/^[a-f0-9]{16}$/D', $generation) !== 1) {
            throw new \UnexpectedValueException('The content response cache generation is invalid.');
        }

        return $generation;
    }

    private function contentResponseScope(Response $response): ?ContentType
    {
        return $this->contentTypeFromScope($response->headers->get(ContentViewResponseProcessor::CONTENT_ID_HEADER));
    }

    private function contentTypeFromScope(?string $scope): ?ContentType
    {
        if ($scope === null || $scope === '') {
            return null;
        }

        try {
            return str_contains($scope, ':')
                ? ContentId::fromString($scope)->type
                : ContentType::from($scope);
        } catch (\Throwable) {
            return null;
        }
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
    private function invalidateOnce(string $key, \Closure $operation, bool $deferUntilCommit): void
    {
        if ($deferUntilCommit) {
            $this->afterCommitOnlyOnce($key, $operation);

            return;
        }

        $this->afterCommitOnce($key, $operation);
    }

    /** @param \Closure(): mixed $operation */
    private function afterCommitOnlyOnce(string $key, \Closure $operation): void
    {
        if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
            $this->pdo->afterCommitOnce('blog-page-cache:' . $key, $operation);

            return;
        }

        $operation();
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
            }

            // Coalesce the completion callback, but not the immediate invalidation:
            // the same transaction may mutate, render, and mutate the dependency
            // again before it commits.
            $operation();

            return;
        }

        $operation();
    }
}
