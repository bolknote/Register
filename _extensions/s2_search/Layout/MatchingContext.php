<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_search
 */

declare(strict_types = 1);

namespace s2_extensions\s2_search\Layout;

/** @phpstan-import-type ImageData from ContentItem */
/** @psalm-import-type ImageData from ContentItem */
class MatchingContext
{
    /** @var ImageData|null */
    private ?array $image = null;

    private string $snippet = '';

    public function __construct(private readonly bool $hasMatch)
    {
    }

    public function hasMatch(): bool
    {
        return $this->hasMatch;
    }

    /** @return ImageData|null */
    public function getImage(): ?array
    {
        return $this->image;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    /** @param ImageData $image */
    public function addImage(array $image, string $class = ''): self
    {
        $this->image          = $image;
        $this->image['class'] = $class;

        return $this;
    }

    public function addSnippet(string $snippet): self
    {
        $this->snippet = $snippet;

        return $this;
    }
}
