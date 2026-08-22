<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity\Metadata;

/**
 * @extends \ArrayIterator<int, Img>
 */
class ImgCollection extends \ArrayIterator
{
    public function __construct(Img ...$images)
    {
        parent::__construct(array_values($images));
    }

    /**
     * @throws \JsonException
     */
    public static function createFromJson(string $json): self
    {
        return new self(...array_map(Img::fromArray(...), json_decode($json, true, 512, JSON_THROW_ON_ERROR)));
    }

    /** @return list<Img> */
    public function toArray(): array
    {
        $images = [];
        foreach ($this->getArrayCopy() as $image) {
            $images[] = $image;
        }

        return $images;
    }

    public function toJson(): string
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
