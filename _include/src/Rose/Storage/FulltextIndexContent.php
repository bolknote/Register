<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage;

use Register\Rose\Entity\ExactWord;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\WordPositionContainer;

class FulltextIndexContent
{
    /**
     * Numeric-string words become integer keys because of PHP array semantics.
     *
     * @var array<int|string, array<string, FulltextIndexPositionBag>>
     */
    protected array $dataByWord = [];

    /**
     * @var array<string, array<int|string, list<int>>>
     */
    protected array $dataByExternalId = [];

    /**
     * @var array<string, array<int|string, list<int>>>
     */
    protected array $titleDataByExternalId = [];

    public function add(string $word, FulltextIndexPositionBag $positionBag): void
    {
        $serializedExtId = $positionBag->getExternalId()->toString();

        // Exact forms rank whole results, but must not multiply phrase-proximity pairs
        // alongside the normalized form stored at the same logical position.
        if (ExactWord::decode($word) === null) {
            $titlePositions = $positionBag->getTitlePositions();
            if (\count($titlePositions) > 0) {
                $this->titleDataByExternalId[$serializedExtId][$word] = $titlePositions;
            }

            $contentPositions = $positionBag->getContentPositions();
            if (\count($contentPositions) > 0) {
                $this->dataByExternalId[$serializedExtId][$word] = $contentPositions;
            }
        }

        $this->dataByWord[$word][$serializedExtId] = $positionBag;
    }

    /**
     * @return array<int|string, array<string, FulltextIndexPositionBag>>
     */
    public function toArray(): array
    {
        return $this->dataByWord;
    }

    public function iterateContentWordPositions(\Closure $callback): void
    {
        foreach ($this->dataByExternalId as $serializedExtId => $data) {
            $callback(ExternalId::fromString($serializedExtId), new WordPositionContainer($data));
        }
    }

    public function iterateTitleWordPositions(\Closure $callback): void
    {
        foreach ($this->titleDataByExternalId as $serializedExtId => $data) {
            $callback(ExternalId::fromString($serializedExtId), new WordPositionContainer($data));
        }
    }
}
