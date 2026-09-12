<?php

declare(strict_types = 1);

namespace Register\Url;

use Register\Core\Config\StringProxy;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/** Preserves tag pages and their subscription URLs after a tag is renamed. */
final readonly class TagUrlAliasRedirector
{
    public function __construct(
        private TagUrlAliasRepository $aliases,
        private UrlBuilder $urls,
        private StringProxy $tagsSegment,
    ) {
    }

    public function redirect(Request $request): ?RedirectResponse
    {
        if (!$request->isMethodSafe()) {
            return null;
        }

        $segments = explode('/', trim($request->getPathInfo(), '/'));
        if (count($segments) < 2 || count($segments) > 3
            || rawurldecode($segments[0]) !== $this->tagsSegment->get()
            || (isset($segments[2]) && !in_array($segments[2], ['rss', 'feed.json'], true))) {
            return null;
        }

        $previous = rawurldecode($segments[1]);
        $current = $this->aliases->currentSlug($previous);
        if ($current === null || $current === $previous) {
            return null;
        }

        $target = $this->urls->rawLink('/' . rawurlencode($this->tagsSegment->get()) . '/' . rawurlencode($current) . '/' . ($segments[2] ?? ''));
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $query;
        }

        return new RedirectResponse($target, 301);
    }
}
