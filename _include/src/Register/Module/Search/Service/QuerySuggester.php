<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Module\Search\Service;

use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Rose\Entity\ExactWord;
use Register\Rose\Entity\FulltextQuery;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultSet;
use Register\Rose\Finder;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Rose\Storage\IndexWordLookupInterface;

/** Offers explicitly selectable corrections, leaving original search results untouched. */
final readonly class QuerySuggester
{
    private const int MAX_QUERY_LENGTH = 256;

    private const int MAX_QUERY_WORDS = 6;

    private const int MAX_LOOKUP_KEYS = 4096;

    private const int MAX_VALIDATIONS = 5;

    private const int MAX_SUGGESTIONS = 3;

    public function __construct(
        private IndexWordLookupInterface $vocabulary,
        private Finder $finder,
        private StemmerInterface $stemmer,
        private HistoricalRussianNormalizer $russianNormalizer,
        private KeyboardLayout $layouts = new KeyboardLayout(),
        private SingleEditVariants $edits = new SingleEditVariants(),
    ) {
    }

    /** @return list<string> */
    public function suggest(Query $query, ResultSet $original): array
    {
        $text = $query->getValue();
        if (!\is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        $words = $query->getSearchWords();
        $fulltext = new FulltextQuery($words, $this->stemmer);
        if (\count($words) > self::MAX_QUERY_WORDS || $fulltext->getQueryTermCount() === 0
            || $original->getMaxMatchedQueryTerms() >= $fulltext->getQueryTermCount()) {
            return [];
        }

        try {
            foreach ($this->layouts->alternatives($text) as $alternative) {
                if ($this->hasCompleteMatch($alternative, $query->getInstanceId())) {
                    return [$alternative];
                }
            }

            return $this->spellingSuggestions($text, $fulltext, $query->getInstanceId());
        } catch (EmptyIndexException) {
            // A concurrently rebuilt index must not turn an optional hint into an error page.
            return [];
        }
    }

    /** @return list<string> */
    private function spellingSuggestions(string $text, FulltextQuery $query, ?int $instanceId): array
    {
        $forms = $query->getWordForms();
        $known = array_fill_keys($this->vocabulary->findExistingIndexWords($query->getWordsWithStems(), $instanceId), true);
        preg_match_all('/(?<![\p{L}\p{N}_-])[a-zа-яё]{4,24}(?![\p{L}\p{N}_-])/iu', $text, $matches, PREG_OFFSET_CAPTURE);
        $unknown = [];
        foreach ($matches[0] as [$word, $offset]) {
            $key = str_replace('ё', 'е', mb_strtolower($word));
            if (!isset($forms[$key]) || array_intersect_key($known, array_fill_keys($forms[$key], true)) !== []) {
                continue;
            }

            $unknown[$key][] = [$word, $offset];
        }

        // Multiple independent mistakes need a different, contextual model.
        if (\count($unknown) !== 1) {
            return [];
        }

        $word = array_key_first($unknown);
        $tokens = $unknown[$word];
        $proposals = $this->candidateWords($word, $instanceId);
        $suggestions = [];
        foreach (\array_slice($proposals, 0, self::MAX_VALIDATIONS) as $proposal) {
            $alternative = $this->replaceWord($text, $tokens, $proposal);
            if ($this->hasCompleteMatch($alternative, $instanceId)) {
                $suggestions[] = $alternative;
            }

            if (\count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return array_values(array_unique($suggestions));
    }

    /** @return list<string> */
    private function candidateWords(string $word, ?int $instanceId): array
    {
        $byIndexKey = [];
        foreach ($this->edits->generate($word) as $variant) {
            if (mb_strlen($variant) < 4) {
                continue;
            }

            $byIndexKey[ExactWord::encode($variant)][$variant] = true;
            // Dictionary lemmas permit a correction even when only inflections
            // occur in the corpus. Bare English Porter stems are not display words.
            foreach ($this->russianNormalizer->normalForms($variant) as $form) {
                $byIndexKey[$form][$variant] = true;
            }

            if (\count($byIndexKey) >= self::MAX_LOOKUP_KEYS) {
                break;
            }
        }

        $scores = [];
        foreach ($this->vocabulary->findExistingIndexWords(array_keys($byIndexKey), $instanceId, 64) as $key) {
            $score = ExactWord::decode($key) !== null ? 2 : 1;
            foreach ($byIndexKey[$key] ?? [] as $variant => $_) {
                $scores[$variant] = max($scores[$variant] ?? 0, $score);
            }
        }

        arsort($scores);

        return array_keys($scores);
    }

    /** @param list<array{string, int}> $tokens Byte offsets as returned by PCRE. */
    private function replaceWord(string $text, array $tokens, string $replacement): string
    {
        foreach (array_reverse($tokens) as [$word, $offset]) {
            $caseReplacement = $replacement;
            if ($word === mb_strtoupper($word)) {
                $caseReplacement = mb_strtoupper($replacement);
            } elseif (mb_substr($word, 0, 1) === mb_strtoupper(mb_substr($word, 0, 1))) {
                $caseReplacement = mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
            }

            $text = substr($text, 0, $offset) . $caseReplacement . substr($text, $offset + \strlen($word));
        }

        return $text;
    }

    private function hasCompleteMatch(string $text, ?int $instanceId): bool
    {
        $query = (new Query($text))->setLimit(1);
        if ($instanceId !== null) {
            $query->setInstanceId($instanceId);
        }

        $needed = (new FulltextQuery($query->getSearchWords(), $this->stemmer))->getQueryTermCount();

        return $needed > 0 && $this->finder->find($query)->getMaxMatchedQueryTerms() >= $needed;
    }
}
