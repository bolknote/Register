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
     * @var array<int, string[]>
     */
    protected array $additionalStems = [];

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
            foreach (StemmerHelper::stemWords($stemmer, $word) as $stemWord) {
                if ($stemWord !== $word) {
                    $this->additionalStems[$i][] = $stemWord;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    public function getWordsWithStems(): array
    {
        $result = $this->words;
        foreach ($this->additionalStems as $stems) {
            array_push($result, ...$stems);
        }

        return $result;
    }

    public function toWordPositionContainer(): WordPositionContainer
    {
        $container = new WordPositionContainer();

        foreach ($this->words as $position => $word) {
            $container->addWordAt($word, $position);
        }

        foreach ($this->additionalStems as $position => $stems) {
            foreach ($stems as $stem) {
                $container->addWordAt($stem, $position);
            }
        }

        return $container;
    }
}
