<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Tools\Search;

use Register\Rose\Entity\ExactWord;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\FulltextQuery;
use Register\Rose\Entity\FulltextResult;
use Register\Rose\Entity\WordPositionContainer;
use Register\Rose\Storage\FulltextIndexContent;
use Register\Rose\Storage\FulltextIndexPositionBag;

/** Frozen old arithmetic for offline comparisons only; not a Finder strategy. */
final class CoverageReference
{
    /** @return array<string, float> Raw old field/proximity scores, before ordering. */
    public function scores(FulltextQuery $query, FulltextIndexContent $index, int $documents): array
    {
        $data = [];
        $frequencies = [];
        foreach ($index->toArray() as $word => $bags) {
            $word = (string)$word;
            $exact = ExactWord::decode($word);
            if ($exact !== null) {
                foreach ($bags as $bag) {
                    $data[$bag->getExternalId()->toString()]['*exact_' . $exact] = 0.0;
                }

                continue;
            }

            $frequency = FulltextResult::frequencyReduction($documents, \count($bags));
            $frequencies[$word] = $frequency;
            foreach ($bags as $bag) {
                $data[$bag->getExternalId()->toString()][$word] = $this->wordWeight($bag, $frequency);
            }
        }

        $reference = $query->toWordPositionContainer();
        $index->iterateContentWordPositions(static function (ExternalId $id, WordPositionContainer $positions) use (&$data, $reference, $frequencies): void {
            self::addPairs($data, $id, $positions, $reference, $frequencies, '*n_');
        });
        $index->iterateTitleWordPositions(static function (ExternalId $id, WordPositionContainer $positions) use (&$data, $reference, $frequencies): void {
            self::addPairs($data, $id, $positions, $reference, $frequencies, '*tn_');
        });

        return array_map(array_sum(...), $data);
    }

    /** Preserve the old per-word addition order as well as the coefficients. */
    private function wordWeight(FulltextIndexPositionBag $bag, float $frequency): float
    {
        $value = 0.0;
        $repeat = \count($bag->getContentPositions());
        $ratio = $bag->getExternalRelevanceRatio();
        if ($repeat > 0) {
            $length = $bag->getWordCount();
            $size = $length < 10 ? 1.0 : 1.0 + 1.0 / (1.0 + exp((sqrt((float)$length) - 18.0) ** 2.0 / 60.0));
            $value = $frequency * min(0.5 * (float)($repeat - 1) + 1.0, 4.0) * $size * $ratio;
        }

        if ($bag->getKeywordPositions() !== []) {
            $value += 10.0 * $frequency * $ratio;
        }

        if ($bag->getTitlePositions() !== []) {
            $value += 25.0 * $frequency * $ratio;
        }

        return $value;
    }

    /**
     * @param array<string, array<string, float>> $data
     * @param array<string, float> $frequencies
     */
    private static function addPairs(array &$data, ExternalId $id, WordPositionContainer $positions, WordPositionContainer $reference, array $frequencies, string $prefix): void
    {
        foreach ($positions->compareWith($reference) as [$first, $second, $distance]) {
            $weight = 30.0 / (1.0 + ((float)$distance / 7.0) ** 2.0)
                * ($frequencies[$first] ?? 1.0) * ($frequencies[$second] ?? 1.0);
            $data[$id->toString()][$prefix . $first . '_' . $second] = $weight;
        }
    }
}
