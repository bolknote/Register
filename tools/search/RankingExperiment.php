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

/** Offline ablations only: public Finder behavior and the index format are unchanged. */
final class RankingExperiment
{
    /** @var list<string> */
    public const array MODELS = ['smooth_tf', 'idf_hard', 'idf_soft', 'idf_smooth', 'idf_smooth_c4', 'idf_smooth_c8'];

    /**
     * Positive BM25-style IDF, scaled so a term found in one document has weight 1.
     * Scaling keeps the existing title/keyword/proximity coefficients comparable.
     */
    public static function rarityWeight(int $documents, int $frequency): float
    {
        $documents = max(1, $documents);
        $frequency = max(1, min($frequency, $documents));
        $idf = log(1.0 + ((float)$documents - (float)$frequency + 0.5) / ((float)$frequency + 0.5));
        $maximum = log(1.0 + ((float)$documents - 0.5) / 1.5);

        return $idf / $maximum;
    }

    /** A smooth alternative to Rose's linear repeat bonus with a hard cap. */
    public static function repeatWeight(int $frequency): float
    {
        if ($frequency < 1) {
            return 0.0;
        }

        return 2.0 * (float)$frequency / (1.0 + (float)$frequency);
    }

    /**
     * @param array<string, float|int> $nativeScores Phrase-filtered, unpaginated native candidates.
     * @param array<string, int|null> $dates
     * @return array<string, list<string>> Serialized external IDs, in full ranking order.
     */
    public function run(FulltextQuery $query, FulltextIndexContent $index, array $nativeScores, int $documents, array $dates): array
    {
        $lookup = $query->getRankingWordPositions();
        $matched = [];
        $groupDocuments = [];
        $exact = [];
        foreach ($index->toArray() as $word => $bags) {
            foreach ($bags as $bag) {
                $id = $bag->getExternalId()->toString();
                foreach ($lookup[(string)$word] ?? [] as $position) {
                    $groupDocuments[$position][$id] = true;
                    $matched[$id][$position] = true;
                }

                $source = ExactWord::decode((string)$word);
                if ($source !== null && isset($lookup[(string)$word])) {
                    $exact[$id][$source] = true;
                }
            }
        }

        $weights = [];
        foreach ($lookup as $positions) {
            foreach ($positions as $position) {
                $weights[$position] = self::rarityWeight($documents, \count($groupDocuments[$position] ?? []));
            }
        }

        $coverage = [];
        $totalWeight = array_sum($weights);
        foreach ($nativeScores as $id => $_) {
            $weight = 0.0;
            foreach ($matched[$id] ?? [] as $position => $_match) {
                $weight += $weights[$position];
            }

            $coverage[$id] = $totalWeight > 0.0 ? $weight / $totalWeight : 0.0;
        }

        $scores = [
            'smooth_tf' => $this->rescore($query, $index, $nativeScores, $documents, false, true),
            'idf_hard' => $this->rescore($query, $index, $nativeScores, $documents, true, false),
            'idf_soft' => $this->rescore($query, $index, $nativeScores, $documents, true, false),
            'idf_smooth' => $this->rescore($query, $index, $nativeScores, $documents, true, true),
        ];
        $scores['idf_smooth_c4'] = $scores['idf_smooth'];
        $scores['idf_smooth_c8'] = $scores['idf_smooth'];

        $rankings = [];
        foreach ($scores as $model => $values) {
            $hard = \in_array($model, ['smooth_tf', 'idf_hard'], true);
            $power = match ($model) {
                'idf_smooth_c4' => 4.0,
                'idf_smooth_c8' => 8.0,
                default => 2.0,
            };
            if (!$hard && $query->getQueryTermCount() > 1) {
                foreach ($values as $id => &$value) {
                    // Coverage is a penalty, not a lexicographic veto. Exact forms
                    // are a small bonus for multiword queries, not a hard priority.
                    $value *= $coverage[$id] ** $power
                        * (1.0 + 0.1 * (float)\count($exact[$id] ?? []) / (float)$query->getQueryTermCount());
                }

                unset($value);
            }

            $ids = array_keys($values);
            usort($ids, static function (string $left, string $right) use ($values, $matched, $exact, $dates, $hard, $query): int {
                if ($hard) {
                    $order = \count($matched[$right] ?? []) <=> \count($matched[$left] ?? []);
                    if ($order !== 0) {
                        return $order;
                    }
                }

                if ($hard || $query->getQueryTermCount() <= 1) {
                    $order = \count($exact[$right] ?? []) <=> \count($exact[$left] ?? []);
                    if ($order !== 0) {
                        return $order;
                    }
                }

                $order = $values[$right] <=> $values[$left];
                if ($order !== 0) {
                    return $order;
                }

                $dateOrder = ($dates[$right] ?? PHP_INT_MIN) <=> ($dates[$left] ?? PHP_INT_MIN);

                return $dateOrder !== 0 ? $dateOrder : strcmp($left, $right);
            });
            $rankings[$model] = $ids;
        }

        return $rankings;
    }

    /**
     * Preserve every native score component except the factors being tested.
     * Exact keys remain ranking/highlighting markers, not duplicate TF entries.
     *
     * @param array<string, float|int> $nativeScores
     * @return array<string, float>
     */
    private function rescore(FulltextQuery $query, FulltextIndexContent $index, array $nativeScores, int $documents, bool $rarity, bool $smooth): array
    {
        $scores = array_map(static fn(float|int $value): float => (float)$value, $nativeScores);
        $oldFrequencies = [];
        $newFrequencies = [];
        foreach ($index->toArray() as $word => $bags) {
            $word = (string)$word;
            if (ExactWord::decode($word) !== null) {
                continue;
            }

            $oldFrequency = FulltextResult::frequencyReduction($documents, \count($bags));
            $newFrequency = $rarity ? self::rarityWeight($documents, \count($bags)) : $oldFrequency;
            $oldFrequencies[$word] = $oldFrequency;
            $newFrequencies[$word] = $newFrequency;
            foreach ($bags as $bag) {
                $id = $bag->getExternalId()->toString();
                if (!\array_key_exists($id, $scores)) {
                    continue;
                }

                $scores[$id] += $this->wordContribution($bag, $newFrequency, $smooth)
                    - $this->wordContribution($bag, $oldFrequency, false);
            }
        }

        if ($rarity) {
            $reference = $query->toWordPositionContainer();
            $adjustPairs = static function (ExternalId $id, WordPositionContainer $positions) use (&$scores, $reference, $oldFrequencies, $newFrequencies): void {
                $key = $id->toString();
                if (!\array_key_exists($key, $scores)) {
                    return;
                }

                foreach ($positions->compareWith($reference) as [$first, $second, $distance]) {
                    $old = ($oldFrequencies[$first] ?? 1.0) * ($oldFrequencies[$second] ?? 1.0);
                    $new = ($newFrequencies[$first] ?? 1.0) * ($newFrequencies[$second] ?? 1.0);
                    $scores[$key] += 30.0 / (1.0 + ((float)$distance / 7.0) ** 2.0) * ($new - $old);
                }
            };
            $index->iterateContentWordPositions($adjustPairs);
            $index->iterateTitleWordPositions($adjustPairs);
        }

        return array_map(static fn(float $value): float => max(0.0, $value), $scores);
    }

    /** Mirror native field coefficients and entry-size weighting to isolate IDF/TF. */
    private function wordContribution(FulltextIndexPositionBag $bag, float $rarity, bool $smooth): float
    {
        $weight = 0.0;
        $ratio = $bag->getExternalRelevanceRatio();
        if ($bag->getTitlePositions() !== []) {
            $weight += 25.0 * $rarity * $ratio;
        }

        if ($bag->getKeywordPositions() !== []) {
            $weight += 10.0 * $rarity * $ratio;
        }

        $frequency = \count($bag->getContentPositions());
        if ($frequency > 0) {
            $repeat = $smooth ? self::repeatWeight($frequency) : min(0.5 * (float)($frequency - 1) + 1.0, 4.0);
            $length = $bag->getWordCount();
            $entrySize = $length < 10 ? 1.0 : 1.0 + 1.0 / (1.0 + exp((sqrt((float)$length) - 18.0) ** 2.0 / 60.0));
            $weight += $rarity * $repeat * $entrySize * $ratio;
        }

        return $weight;
    }
}
