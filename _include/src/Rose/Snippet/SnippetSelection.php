<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Snippet;

use Register\Rose\Entity\SnippetLine;

/**
 * Greedily covers distinct phrases and query terms, then uses the existing relevance.
 *
 * @phpstan-type Matches array{terms: array<int, true>, phrases: array<int, true>}
 * @see \Register\Rose\Test\Snippet\SnippetSelectionTest
 */
final readonly class SnippetSelection
{
    /**
     * @param array<int, list<int>> $termPositions
     * @param array<int, array{starts: list<int>, length: int}> $phraseMatches
     */
    public function __construct(private array $termPositions, private array $phraseMatches)
    {
    }

    /**
     * @param array<int, array{line: SnippetLine, min: int, max: int}> $candidates
     * @return list<int> Selected indexes in document order.
     */
    public function select(array $candidates, int $limit): array
    {
        $matches = [];
        foreach ($candidates as $index => $candidate) {
            $matches[$index] = $this->matches($candidate['min'], $candidate['max']);
        }

        $selected = [];
        $covered = ['terms' => [], 'phrases' => []];
        $seenText = [];
        while ($candidates !== [] && \count($selected) < $limit) {
            $best = array_key_first($candidates);
            $bestScore = [];
            foreach ($candidates as $index => $candidate) {
                $score = [
                    \count(array_diff_key($matches[$index]['phrases'], $covered['phrases'])),
                    \count(array_diff_key($matches[$index]['terms'], $covered['terms'])),
                    $candidate['line']->getRelevance(),
                    -$candidate['min'],
                    -$index,
                ];
                if ($score > $bestScore) {
                    $best = $index;
                    $bestScore = $score;
                }
            }

            $chosen = $candidates[$best];
            $selected[$best] = $chosen;
            $covered['terms'] += $matches[$best]['terms'];
            $covered['phrases'] += $matches[$best]['phrases'];
            $seenText[$chosen['line']->getLine()] = true;
            foreach ($candidates as $index => $candidate) {
                if (isset($seenText[$candidate['line']->getLine()])
                    || ($candidate['min'] <= $chosen['max'] && $candidate['max'] >= $chosen['min'])) {
                    unset($candidates[$index]);
                }
            }
        }

        uasort($selected, static fn(array $left, array $right): int => $left['min'] <=> $right['min']);

        return array_keys($selected);
    }

    /** @return Matches */
    private function matches(int $min, int $max): array
    {
        $terms = [];
        foreach ($this->termPositions as $term => $positions) {
            if ($this->containsPosition($positions, $min, $max)) {
                $terms[$term] = true;
            }
        }

        $phrases = [];
        foreach ($this->phraseMatches as $phrase => $match) {
            if ($this->containsPosition($match['starts'], $min, $max - $match['length'] + 1)) {
                $phrases[$phrase] = true;
            }
        }

        return ['terms' => $terms, 'phrases' => $phrases];
    }

    /** @param list<int> $positions */
    private function containsPosition(array $positions, int $min, int $max): bool
    {
        foreach ($positions as $position) {
            if ($position >= $min && $position <= $max) {
                return true;
            }
        }

        return false;
    }
}
