<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use S2\Cms\Config\StringProxy;

/**
 * Owns the first path segments that cannot be used by root content.
 *
 * Keeping this list at product level prevents a post or a top-level page from silently shadowing
 * an application endpoint. Dynamic tag/favorite paths remain supported until those legacy
 * settings are replaced by fixed Register routes.
 */
final readonly class ReservedRouteRegistry
{
    /** @var list<string> */
    private const array FIXED_SEGMENTS = [
        '_admin',
        '_analytics',
        '_assets',
        '_cache',
        '_include',
        '_inplace',
        '_live',
        '_pictures',
        '_styles',
        '.well-known',
        'activitypub',
        'all',
        'archive',
        'comment-moderate',
        'comment_sent',
        'comment_unsubscribe',
        'favicon.ico',
        'index.php',
        'robots.txt',
        'rss.xml',
        'search',
        'service-worker.js',
        'sitemap.xml',
        'skip',
    ];

    public function __construct(
        private StringProxy|string $tagsSegment,
        private StringProxy|string $favoriteSegment,
    ) {
    }

    public function contains(string $slug): bool
    {
        $slug = strtolower($slug);

        return \in_array($slug, $this->segments(), true);
    }

    /** @return list<string> */
    public function segments(): array
    {
        return array_values(array_unique([
            ...self::FIXED_SEGMENTS,
            strtolower($this->value($this->tagsSegment)),
            strtolower($this->value($this->favoriteSegment)),
        ]));
    }

    private function value(StringProxy|string $segment): string
    {
        return $segment instanceof StringProxy ? $segment->get() : $segment;
    }
}
