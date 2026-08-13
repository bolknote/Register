<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Layout;

class ImgDto
{
    private readonly float $width;

    private readonly float $height;

    /**
     * @var string[]
     */
    private array $srcSet = [];

    public function __construct(private readonly string $src, float $width, float $height, private readonly string $class)
    {
        if ($width < 1 || $height < 1) {
            throw new \DomainException(\sprintf('Invalid image dimensions: "%s" "%s".', $width, $height));
        }

        $this->width  = $width;
        $this->height = $height;
    }

    public function getSrc(): string
    {
        return $this->src;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getRatio(): float
    {
        return $this->height / $this->width;
    }

    public function addSrc(string $src): self
    {
        $this->srcSet[] = $src;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getSrcSet(): array
    {
        return $this->srcSet;
    }
}
