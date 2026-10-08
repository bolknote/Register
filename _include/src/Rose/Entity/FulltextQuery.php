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
    // Keep connectors searchable, but do not let them decide coverage or exactness
    // when the query also contains content words. Negation remains significant.
    private const array CONNECTORS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'from', 'with',
        'а', 'и', 'или', 'в', 'во', 'на', 'о', 'об', 'обо', 'от', 'к', 'ко', 'до', 'для',
        'из', 'с', 'со', 'у', 'по', 'при',
    ];

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

    /** @return array<int|string, non-empty-list<string>> */
    public function getWordForms(): array
    {
        $result = [];
        foreach ($this->words as $position => $word) {
            $result[$word] = array_values(array_unique([ExactWord::encode($word), ...$this->normalizedWords[$position]]));
        }

        return $result;
    }

    /**
     * Maps all alternative index keys to their original query terms. A word with
     * several possible lemmas still contributes only one coverage match.
     *
     * @return array<string, list<int>>
     */
    public function getRankingWordPositions(): array
    {
        $words = array_filter($this->words, static fn(string $word): bool => preg_match('#[\p{L}\d]#u', $word) === 1);
        $contentWords = array_filter($words, static fn(string $word): bool => !\in_array(mb_strtolower($word), self::CONNECTORS, true));
        if ($contentWords !== []) {
            $words = $contentWords;
        }

        $result = [];
        foreach ($words as $position => $word) {
            foreach ([ExactWord::encode($word), ...$this->normalizedWords[$position]] as $indexWord) {
                $result[$indexWord][] = $position;
            }
        }

        return $result;
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
