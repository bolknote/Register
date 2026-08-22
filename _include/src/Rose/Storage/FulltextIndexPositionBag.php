<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage;

use Register\Rose\Entity\ExternalId;

class FulltextIndexPositionBag
{
    /**
     * @param list<int> $titlePositions
     * @param list<int> $keywordPositions
     * @param list<int> $contentPositions
     */
    public function __construct(private readonly ExternalId $externalId, private readonly array      $titlePositions, private readonly array      $keywordPositions, private readonly array      $contentPositions, private readonly int        $wordCount, private readonly float      $externalRelevanceRatio)
    {
    }

    public function getExternalId(): ExternalId
    {
        return $this->externalId;
    }

    /** @return list<int> */
    public function getTitlePositions(): array
    {
        return $this->titlePositions;
    }

    /** @return list<int> */
    public function getKeywordPositions(): array
    {
        return $this->keywordPositions;
    }

    /** @return list<int> */
    public function getContentPositions(): array
    {
        return $this->contentPositions;
    }

    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    public function getExternalRelevanceRatio(): float
    {
        return $this->externalRelevanceRatio;
    }

    public function hasExternalRelevanceRatio(): bool
    {
        return $this->externalRelevanceRatio !== 1.0;
    }
}
