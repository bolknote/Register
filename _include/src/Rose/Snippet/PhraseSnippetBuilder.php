<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Snippet;

use Register\Rose\Entity\Metadata\SnippetSource;

/**
 * Joins adjacent stored fragments when a phrase crosses a sentence boundary.
 *
 * @see \Register\Rose\Test\Snippet\PhraseSnippetBuilderTest
 */
final class PhraseSnippetBuilder
{
    private const int MAX_PARTS = 3;

    private const int MAX_LENGTH = 4096;

    private const int MAX_WINDOWS_PER_PHRASE = 3;

    /**
     * @param list<SnippetSource> $sources
     * @param array<int, array{starts: list<int>, length: int}> $phrases
     * @return list<SnippetSource>
     */
    public function extend(array $sources, array $phrases): array
    {
        if ($phrases === []) {
            return $sources;
        }

        usort($sources, static fn(SnippetSource $left, SnippetSource $right): int => $left->getMinPosition() <=> $right->getMinPosition());
        $joined = [];
        foreach ($phrases as $phrase) {
            $windows = 0;
            foreach ($phrase['starts'] as $start) {
                $source = $this->joinAt($sources, $start, $start + $phrase['length'] - 1);
                if ($source === null) {
                    continue;
                }

                $key = $source->getMinPosition() . ':' . $source->getMaxPosition();
                if (isset($joined[$key])) {
                    continue;
                }

                $joined[$key] = $source;
                if (++$windows >= self::MAX_WINDOWS_PER_PHRASE) {
                    break;
                }
            }
        }

        return array_merge($sources, array_values($joined));
    }

    /** @param list<SnippetSource> $sources */
    private function joinAt(array $sources, int $start, int $end): ?SnippetSource
    {
        $index = $this->sourceIndex($sources, $start);
        if ($index === null) {
            return null;
        }

        $first = $sources[$index];
        if ($first->getMaxPosition() >= $end) {
            return null;
        }

        $text = $first->getText();
        $max = $first->getMaxPosition();
        for ($parts = 1; $parts < self::MAX_PARTS; ++$parts) {
            $next = $sources[++$index] ?? null;
            if ($next === null || $next->getMinPosition() !== $max + 1 || $next->getFormatId() !== $first->getFormatId()) {
                return null;
            }

            $text .= ' ' . $next->getText();
            if (\strlen($text) > self::MAX_LENGTH) {
                return null;
            }

            $max = $next->getMaxPosition();
            if ($max >= $end) {
                return new SnippetSource($text, $first->getFormatId(), $first->getMinPosition(), $max);
            }
        }

        return null;
    }

    /** @param list<SnippetSource> $sources */
    private function sourceIndex(array $sources, int $position): ?int
    {
        $low = 0;
        $high = \count($sources) - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($sources[$middle]->getMinPosition() <= $position) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        if ($found === null) {
            return null;
        }

        return $sources[$found]->getMaxPosition() >= $position ? $found : null;
    }
}
