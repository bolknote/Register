<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Controller\Rss\RssHitEvent;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Http\Cache\EarlyPageCache;
use Register\Core\Http\Cache\PageCacheHeaders;
use Register\Core\Http\Cache\PublicPageCacheRequest;
use Register\Core\Http\Cache\QueryParameterDependencies;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\BlogResponseCachePolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Explicit opt-in for public listings and feeds, never private or query-dependent feeds. */
final readonly class PublicCollectionController implements ControllerInterface
{
    public function __construct(
        private ControllerInterface $controller,
        private BlogPageCache $cache,
        private BlogResponseCachePolicy $policy,
        private EventDispatcherInterface $eventDispatcher,
        private bool $paginated = false,
        private ?RssStrategyInterface $feedStrategy = null,
    ) {
    }

    public static function serviceId(string $controller): string
    {
        return 'register_blog.public_cache.' . $controller;
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $query = $request->query->all();
        $page = $query['p'] ?? null;
        $pagination = $this->paginated && count($query) === 1 && is_string($page)
            ? PublicPageCacheRequest::fromUri($request->getPathInfo() . '?p=' . $page)
            : null;
        if ($query !== [] && $pagination === null) {
            return $this->controller->handle($request);
        }

        $variant = $this->policy->variant($request, QueryParameterDependencies::none());
        if ($variant === null) {
            return $this->controller->handle($request);
        }

        if ($pagination !== null) {
            $request->attributes->set(PublicPageCacheRequest::ATTRIBUTE, true);
        }

        $response = $this->cache->publicResponse(
            $variant,
            $request->getBaseUrl() . $request->getPathInfo() . ($pagination === null ? '' : '?p=' . $page),
            fn(): Response => $this->render($request),
        );
        if ($this->feedStrategy !== null) {
            // This is an origin representation validated against the current
            // content generation. Its HTTP freshness starts at this validation,
            // independently of the lifetime of the stored rendering inputs.
            $response->setDate(new \DateTimeImmutable());
            $response->headers->remove('Age');
            if ($response->headers->get(PageCacheHeaders::STATUS) === 'hit') {
                $this->eventDispatcher->dispatch(new RssHitEvent($request, $this->feedStrategy));
            }

            $channel = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($this->feedStrategy->getId()));
            $request->attributes->set(EarlyPageCache::FEED_ATTRIBUTE, 'feed:' . ($channel === null || $channel === '' ? 'other' : $channel));
            $response->isNotModified($request);
        }

        return $response;
    }

    private function render(Request $request): Response
    {
        // Cache the complete feed, even when the cold request carries a validator.
        // Keep the actual request in RequestStack for feed listeners and placeholders.
        $validators = [];
        foreach (['If-None-Match', 'If-Modified-Since'] as $header) {
            if ($request->headers->has($header)) {
                $validators[$header] = array_filter($request->headers->all($header), static fn(?string $value): bool => $value !== null);
                $request->headers->remove($header);
            }
        }

        try {
            return $this->controller->handle($request);
        } finally {
            foreach ($validators as $header => $values) {
                $request->headers->set($header, $values);
            }
        }
    }
}
