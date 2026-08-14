<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\Model\UrlBuilder;

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

    public function tag(string $tagUrl): string
    {
        return $this->tags() . rawurlencode($tagUrl) . '/';
    }

    public function year(int $year): string
    {
        return $this->main() . $year . '/';
    }

    public function month(int $year, int $month): string
    {
        return $this->main() . $year . '/' . $this->extendNumber($month) . '/';
    }

    public function monthFromTimestamp(int $timestamp): string
    {
        return $this->main() . date('Y/m/', $timestamp);
    }

    public function day(int $year, int $month, int $day): string
    {
        return $this->main() . $year . '/' . $this->extendNumber($month) . '/' . $this->extendNumber($day) . '/';
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
