<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity;

use S2\Rose\Entity\Metadata\ImgCollection;
use S2\Rose\Entity\Metadata\SentenceMap;

class ContentWithMetadata
{
    public function __construct(private readonly SentenceMap $sentenceMap, private readonly ImgCollection $imageCollection)
    {
    }

    public function getSentenceMap(): SentenceMap
    {
        return $this->sentenceMap;
    }

    public function getImageCollection(): ImgCollection
    {
        return $this->imageCollection;
    }
}
