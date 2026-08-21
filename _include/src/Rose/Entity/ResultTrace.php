<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity;

class ResultTrace
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param array<string, float|int> $weights
     * @param list<int>                $positions
     */
    public function addWordWeight(string $word, string $serializedExtId, array $weights, array $positions): void
    {
        $this->data[$serializedExtId]['fulltext ' . $word][] = [
            sprintf(
                '%s: match at positions [%s]',
                array_product($weights),
                implode(', ', $positions)
            ) => $weights,
        ];
    }

    /**
     * @param array<string, float|int> $weights
     */
    public function addKeywordWeight(string $word, string $serializedExtId, array $weights): void
    {
        $this->data[$serializedExtId]['keyword ' . $word][] = [
            (string)array_product($weights) => $weights,
        ];
    }

    public function addNeighbourWeight(string $word1, string $word2, string $serializedExtId, float $weight, int $distance): void
    {
        $this->data[$serializedExtId]['fulltext ' . $word1 . ' - ' . $word2][] = sprintf('%s: matches are close (shift = %d)', $weight, $distance);
    }

    public function addTitleNeighbourWeight(string $word1, string $word2, string $serializedExtId, float $weight, int $distance): void
    {
        $this->data[$serializedExtId]['title ' . $word1 . ' - ' . $word2][] = sprintf('%s: matches are close (shift = %d)', $weight, $distance);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
