<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\Core\Config\StringProxy;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\UrlBuilder;

class BlogUrlBuilder implements StatefulServiceInterface
{
    private ?string $blogPath = null;

    private ?string $absBlogPath = null;

    private ?string $blogTagsPath = null;

    public function __construct(
        private readonly UrlBuilder  $urlBuilder,
        private readonly StringProxy $tagsUrl,
        private readonly StringProxy $favoriteUrl,
    ) {
    }

    public function main(): string
    {
        return $this->blogPath ?? $this->blogPath = $this->urlBuilder->link('/');
    }

    public function absMain(): string
    {
        return $this->absBlogPath ?? $this->absBlogPath = $this->urlBuilder->absLink('/');
    }

    public function jsonFeed(): string
    {
        return $this->main() . 'feed.json';
    }

    public function absJsonFeed(): string
    {
        return $this->absMain() . 'feed.json';
    }

    public function rss(): string
    {
        return $this->main() . 'rss';
    }

    public function absRss(): string
    {
        return $this->absMain() . 'rss';
    }

    public function favorite(): string
    {
        return $this->main() . rawurlencode($this->favoriteUrl->get()) . '/';
    }

    public function tags(): string
    {
        return $this->blogTagsPath ?? $this->blogTagsPath = $this->main() . rawurlencode($this->tagsUrl->get()) . '/';
    }

    public function all(): string
    {
        return $this->main() . 'all/';
    }

    public function popular(): string
    {
        return $this->main() . 'popular/';
    }

    public function hot(): string
    {
        return $this->main() . 'hot/';
    }

    public function random(): string
    {
        return $this->main() . 'random/';
    }

    public function tag(string $tagUrl): string
    {
        return $this->tags() . rawurlencode($tagUrl) . '/';
    }

    /** Returns an HTML-safe sprintf pattern used by compact tag pagination. */
    public function tagPagePattern(string $tagUrl): string
    {
        $url = str_replace('%', '%%', $this->tag($tagUrl));

        return $url . (str_contains($url, '?') ? '&amp;' : '?') . 'p=%d';
    }

    public function absTag(string $tagUrl): string
    {
        return $this->absMain() . rawurlencode($this->tagsUrl->get()) . '/' . rawurlencode($tagUrl) . '/';
    }

    public function tagRss(string $tagUrl): string
    {
        return $this->tag($tagUrl) . 'rss';
    }

    public function tagJsonFeed(string $tagUrl): string
    {
        return $this->tag($tagUrl) . 'feed.json';
    }

    public function absTagRss(string $tagUrl): string
    {
        return $this->absTag($tagUrl) . 'rss';
    }

    public function absTagJsonFeed(string $tagUrl): string
    {
        return $this->absTag($tagUrl) . 'feed.json';
    }

    public function year(int $year): string
    {
        return $this->main() . 'archive/' . $year . '/';
    }

    public function month(int $year, int $month): string
    {
        return $this->main() . 'archive/' . $year . '/' . $this->extendNumber($month) . '/';
    }

    public function monthFromTimestamp(int $timestamp): string
    {
        return $this->main() . 'archive/' . date('Y/m/', $timestamp);
    }

    public function day(int $year, int $month, int $day): string
    {
        return $this->main() . 'archive/' . $year . '/' . $this->extendNumber($month) . '/' . $this->extendNumber($day) . '/';
    }

    #[\Override]
    public function clearState(): void
    {
        $this->blogPath = null;
        $this->absBlogPath = null;
        $this->blogTagsPath = null;
    }

    private function extendNumber(int $month): string
    {
        return str_pad((string)$month, 2, '0', STR_PAD_LEFT);
    }
}
