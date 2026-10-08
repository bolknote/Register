<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose;

use Register\Rose\Entity\ExactWord;
use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\FulltextQuery;
use Register\Rose\Storage\FulltextIndexContent;
use Register\Rose\Storage\FulltextIndexPositionBag;
use Register\Rose\Storage\StorageReadInterface;

/**
 * Intersects phrase occurrences, preserving field boundaries and repeated tokens.
 *
 * @phpstan-type FieldPositions array<int<0, 2>, array<int, true>>
 * @phpstan-type TermPositions array<string, FieldPositions>
 */
final readonly class PhraseMatcher
{
    public function __construct(private StorageReadInterface $storage)
    {
    }

    /** @param non-empty-list<non-empty-list<string>> $phrases */
    public function findMatchingIds(array $phrases, FulltextQuery $query, FulltextIndexContent $index): ExternalIdCollection
    {
        $positionsByTerm = [];
        $ignoredTerms = [];
        $data = $index->toArray();
        foreach ($query->getWordForms() as $word => $forms) {
            $positionsByTerm[$word] = $this->termPositions($forms, $data);
            if ($this->isExcluded($forms)) {
                $ignoredTerms[$word] = true;
            }
        }

        $allowed = $this->matchPhrase($phrases[0], $positionsByTerm, $ignoredTerms);
        foreach (\array_slice($phrases, 1) as $phrase) {
            if ($allowed === []) {
                break;
            }

            $matches = $this->matchPhrase($phrase, $positionsByTerm, $ignoredTerms);
            $allowed = array_intersect_key($allowed, $matches);
        }

        return ExternalIdCollection::fromStringArray(array_keys($allowed));
    }

    /**
     * @param non-empty-list<string> $phrase
     * @param array<int|string, array<string, array<int<0, 2>, array<int, true>>>> $positionsByTerm
     * @param array<int|string, true> $ignoredTerms
     * @return array<string, true>
     */
    private function matchPhrase(array $phrase, array $positionsByTerm, array $ignoredTerms): array
    {
        $matches = null;
        $origin = 0;
        foreach ($phrase as $offset => $word) {
            // Missing query terms (including those beyond the lookup limit) must
            // never turn a required whole phrase into a prefix-only match.
            if (!isset($positionsByTerm[$word])) {
                return [];
            }

            if (isset($ignoredTerms[$word])) {
                continue;
            }

            if ($matches === null) {
                $matches = $positionsByTerm[$word];
                $origin = $offset;
            } else {
                $matches = $this->extendMatches($matches, $positionsByTerm[$word], $offset - $origin);
            }

            if ($matches === []) {
                return [];
            }
        }

        return array_fill_keys(array_keys($matches ?? []), true);
    }

    /**
     * @param non-empty-list<string> $forms
     * @param array<int|string, array<string, FulltextIndexPositionBag>> $index
     * @return TermPositions
     */
    private function termPositions(array $forms, array $index): array
    {
        $result = [];
        foreach ($forms as $form) {
            foreach ($index[$form] ?? [] as $bag) {
                $id = $bag->getExternalId()->toString();
                foreach ([$bag->getTitlePositions(), $bag->getKeywordPositions(), $bag->getContentPositions()] as $field => $positions) {
                    foreach ($positions as $position) {
                        $result[$id][$field][$position] = true;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param TermPositions $starts
     * @param TermPositions $following
     * @return TermPositions
     */
    private function extendMatches(array $starts, array $following, int $shift): array
    {
        $result = [];
        foreach ($starts as $id => $fields) {
            foreach ($fields as $field => $positions) {
                foreach ($positions as $start => $_) {
                    if (isset($following[$id][$field][$start + $shift])) {
                        $result[$id][$field][$start] = true;
                    }
                }
            }
        }

        return $result;
    }

    /** @param non-empty-list<string> $forms */
    private function isExcluded(array $forms): bool
    {
        // File-backed indexes may deliberately omit common terms. Preserve their
        // positional gaps, just as a phrase analyzer with a stop-word policy does.
        foreach ($forms as $form) {
            if (ExactWord::decode($form) === null && !$this->storage->isExcludedWord($form)) {
                return false;
            }
        }

        return true;
    }
}
