<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

use Register\Rose\Stemmer\StemmerHelper;
use Register\Rose\Stemmer\StemmerInterface;

class FulltextQuery
{
    /**
     * @var array<int, non-empty-list<string>>
     */
    protected array $normalizedWords = [];

    /**
     * @param list<string> $words
     */
    public function __construct(protected array $words, StemmerInterface $stemmer)
    {
        $this->extractStems($stemmer);
    }

    protected function extractStems(StemmerInterface $stemmer): void
    {
        foreach ($this->words as $i => $word) {
            $this->normalizedWords[$i] = StemmerHelper::stemWords($stemmer, $word);
        }
    }

    /**
     * @return string[]
     */
    public function getWordsWithStems(): array
    {
        $result = [];
        foreach ($this->words as $position => $word) {
            $result[] = ExactWord::encode($word);
            array_push($result, ...$this->normalizedWords[$position]);
        }

        return array_values(array_unique($result));
    }

    public function toWordPositionContainer(): WordPositionContainer
    {
        $container = new WordPositionContainer();

        foreach ($this->normalizedWords as $position => $words) {
            foreach ($words as $stem) {
                $container->addWordAt($stem, $position);
            }
        }

        return $container;
    }
}
