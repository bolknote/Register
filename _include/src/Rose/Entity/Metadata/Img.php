<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity\Metadata;

class Img implements \JsonSerializable
{
    public function __construct(private readonly string $src, private readonly string $width, private readonly string $height, private readonly string $alt)
    {
    }

    public function getSrc(): string
    {
        return $this->src;
    }

    public function getWidth(): string
    {
        return $this->width;
    }

    public function getHeight(): string
    {
        return $this->height;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    /**
     * @param array<string, mixed> $img
     */
    public static function fromArray(array $img): Img
    {
        return new self($img['src'], $img['width'], $img['height'], $img['alt']);
    }

    /** @return array{src: string, width: string, height: string, alt: string} */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'src'    => $this->src,
            'width'  => $this->width,
            'height' => $this->height,
            'alt'    => $this->alt,
        ];
    }

    public function hasNumericDimensions(): bool
    {
        return is_numeric($this->width) && is_numeric($this->height);
    }
}
