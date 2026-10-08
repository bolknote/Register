<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

use Register\Rose\Exception\ImmutableException;
use Register\Rose\Storage\FulltextIndexContent;

/**
 * @see \Register\Rose\Test\Entity\FulltextResultTest
 */
class FulltextResult
{
    public function __construct(
        protected FulltextQuery $query,
        protected FulltextIndexContent $fulltextIndexContent,
        protected int $tocSize = 0,
    ) {
    }

    /** Positive IDF, normalized to 1 for a word found in one document. */
    public static function rarityWeight(int $documents, int $frequency): float
    {
        $documents = max(1, $documents);
        $frequency = max(1, min($frequency, $documents));
        $idf = log(1.0 + ((float)$documents - (float)$frequency + 0.5) / ((float)$frequency + 0.5));
        $maximum = log(1.0 + ((float)$documents - 0.5) / 1.5);

        return $idf / $maximum;
    }

    /**
     * Legacy arithmetic helper retained for API compatibility and offline references;
     * Finder does not use it.
     *
     * https://i.upmath.me/svg/%5Cbegin%7Btikzpicture%7D%5Bscale%3D1.0544%5D%5Csmall%0A%5Cbegin%7Baxis%7D%5Baxis%20line%20style%3Dgray%2C%0A%09samples%3D100%2C%0A%09xmin%3D-1.2%2C%20xmax%3D1.2%2C%0A%09ymin%3D0%2C%20ymax%3D1.1%2C%0A%09restrict%20y%20to%20domain%3D-0.1%3A1%2C%0A%09ytick%3D%7B1%7D%2C%0A%09xtick%3D%7B-1%2C1%7D%2C%0A%09axis%20equal%2C%0A%09axis%20x%20line%3Dcenter%2C%0A%09axis%20y%20line%3Dcenter%2C%0A%09xlabel%3D%24x%24%2Cylabel%3D%24y%24%5D%0A%5Caddplot%5Bred%2Cdomain%3D-2%3A1%2Csemithick%5D%7Bexp(-(x%2F0.38)%5E2)%7D%3B%0A%5Caddplot%5Bred%5D%20coordinates%20%7B(0.8%2C0.6)%7D%20node%7B%24y%3De%5E%7B-%5Cleft(x%2F0.38%5Cright)%5E2%7D%24%7D%3B%0A%5Cpath%20(axis%20cs%3A0%2C0)%20node%20%5Banchor%3Dnorth%20west%2Cyshift%3D-0.07cm%5D%20%7B0%7D%3B%0A%5Cend%7Baxis%7D%0A%5Cend%7Btikzpicture%7D
     */
    public static function frequencyReduction(int $tocSize, int $foundTocEntriesNum): float
    {
        if ($tocSize < 5) {
            return 1;
        }

        $ratio = (float)$foundTocEntriesNum / (float)$tocSize / 0.38;

        return exp(-$ratio ** 2.0);
    }

    /**
     * Weight ratio for repeating words in the indexed item.
     */
    protected static function repeatWeightRatio(int $repeatNum): float
    {
        return min(0.5 * (float)($repeatNum - 1) + 1.0, 4.0);
    }

    /**
     * Weight ratio for entry size (prefer some middle size)
     *
     * https://i.upmath.me/g/%5Cbegin%7Btikzpicture%7D%5Bscale%3D1.0544%5D%5Csmall%0A%5Cbegin%7Baxis%7D%5Baxis%20line%20style%3Dgray%2C%0A%09samples%3D100%2C%0A%09ymin%3D0%2C%20ymax%3D5%2C%0A%09xmin%3D0%2C%20xmax%3D1100%2C%0A%09ytick%3D%7B1%2C2%7D%2C%0A%09xtick%3D%7B50%2C200%2C500%2C1000%7D%2C%0A%09axis%20x%20line%3Dcenter%2C%0A%09axis%20y%20line%3Dcenter%2C%0A%09xlabel%3D%24x%24%2Cylabel%3D%24y%24%5D%0A%5Caddplot%5Bred%2Cdomain%3D0%3A1000%2Csemithick%5D%7B1%2F(1%2Bexp((sqrt(x)-18)%5E2%2F60))%2B1%7D%3B%0A%5Caddplot%5Bblue%2Cdomain%3D0%3A1000%2Csemithick%5D%7B1%7D%3B%0A%5Caddplot%5Bred%5D%20coordinates%20%7B(600%2C3)%7D%20node%7B%24y%3D1%2F(1%2Bexp((sqrt(x)-18)%5E2%2F60))%2B1%24%7D%3B%0A%5Cend%7Baxis%7D%0A%5Cend%7Btikzpicture%7D
     */
    protected static function entrySizeWeightRatio(int $totalWordsNum): float
    {
        if ($totalWordsNum < 10) {
            return 1.0;
        }

        $rootDistance = sqrt((float)$totalWordsNum) - 18.0;

        return 1.0 + 1.0 / (1.0 + exp($rootDistance ** 2.0 / 60.0));
    }

    /**
     * Weight ratio for a pair of words. Accepts the difference of distances
     * in the indexed item and the search query.
     *
     *
     */
    protected static function neighbourWeight(float $distance): float
    {
        return 30.0 / (1.0 + ($distance / 7.0) ** 2.0);
    }

    /**
     * @throws ImmutableException
     */
    public function fillResultSet(ResultSet $resultSet): void
    {
        $dates = $this->fulltextIndexContent->getEntryDates();
        if ($dates !== []) {
            $resultSet->setRankingDates($dates);
        }

        $rankingWordPositions = $this->query->getRankingWordPositions();
        $resultSet->setSnippetWordGroups($rankingWordPositions);
        $resultSet->setRarityQueryTermWeights($this->rarityQueryTermWeights($rankingWordPositions));

        $wordReductionRatios = [];
        foreach ($this->fulltextIndexContent->toArray() as $word => $indexedItems) {
            $word                       = (string)$word;
            foreach ($indexedItems as $positionBag) {
                foreach ($rankingWordPositions[$word] ?? [] as $queryPosition) {
                    $resultSet->addQueryTermMatch($queryPosition, $positionBag->getExternalId());
                }
            }

            $exactWord                  = ExactWord::decode($word);
            if ($exactWord !== null) {
                foreach ($indexedItems as $positionBag) {
                    $resultSet->addExactMatch(
                        $exactWord,
                        $positionBag->getExternalId(),
                        $positionBag->getContentPositions(),
                        isset($rankingWordPositions[$word]),
                    );
                }

                continue;
            }

            $reductionRatio             = self::rarityWeight($this->tocSize, \count($indexedItems));
            $wordReductionRatios[$word] = $reductionRatio;

            foreach ($indexedItems as $positionBag) {
                $externalId          = $positionBag->getExternalId();
                $contentPositionsNum = \count($positionBag->getContentPositions());

                if ($contentPositionsNum > 0) {
                    $weights = [
                        'abundance_reduction' => $reductionRatio,
                        'repeat_multiply'     => self::repeatWeightRatio($contentPositionsNum),
                        'entry_size'          => self::entrySizeWeightRatio($positionBag->getWordCount()),
                    ];
                    if ($positionBag->hasExternalRelevanceRatio()) {
                        $weights['external_ratio'] = $positionBag->getExternalRelevanceRatio();
                    }

                    $resultSet->addWordWeight($word, $externalId, $weights, $positionBag->getContentPositions());
                }

                if (\count($positionBag->getKeywordPositions()) > 0) {
                    $weights = [
                        'keyword'             => 10,
                        'abundance_reduction' => $reductionRatio,
                    ];
                    if ($positionBag->hasExternalRelevanceRatio()) {
                        $weights['external_ratio'] = $positionBag->getExternalRelevanceRatio();
                    }

                    $resultSet->addWordWeight($word, $externalId, $weights);
                }

                if (\count($positionBag->getTitlePositions()) > 0) {
                    $weights = [
                        'title'               => 25,
                        'abundance_reduction' => $reductionRatio,
                    ];
                    if ($positionBag->hasExternalRelevanceRatio()) {
                        $weights['external_ratio'] = $positionBag->getExternalRelevanceRatio();
                    }

                    $resultSet->addWordWeight($word, $externalId, $weights);
                }
            }
        }

        $referenceContainer = $this->query->toWordPositionContainer();

        $this->fulltextIndexContent->iterateContentWordPositions(
            static function (ExternalId $id, WordPositionContainer $container) use ($referenceContainer, $wordReductionRatios, $resultSet): void {
                self::addNeighbourWeights($id, $container, $referenceContainer, $wordReductionRatios, $resultSet, false);
            }
        );

        $this->fulltextIndexContent->iterateTitleWordPositions(
            static function (ExternalId $id, WordPositionContainer $container) use ($referenceContainer, $wordReductionRatios, $resultSet): void {
                self::addNeighbourWeights($id, $container, $referenceContainer, $wordReductionRatios, $resultSet, true);
            }
        );
    }

    /**
     * Count documents once per original term, across exact forms and all lemmas.
     * Missing terms keep a weight, so partial matches still incur a penalty.
     *
     * @param array<string, list<int>> $positions
     * @return array<int, float>
     */
    private function rarityQueryTermWeights(array $positions): array
    {
        $groups = [];
        $postings = $this->fulltextIndexContent->toArray();
        foreach ($positions as $word => $queryPositions) {
            foreach ($queryPositions as $position) {
                $groups[$position] = ($groups[$position] ?? []) + ($postings[$word] ?? []);
            }
        }

        $weights = [];
        foreach ($groups as $position => $documents) {
            $weights[$position] = self::rarityWeight($this->tocSize, \count($documents));
        }

        return $weights;
    }

    /** @param array<string, float> $wordReductionRatios */
    private static function addNeighbourWeights(
        ExternalId            $id,
        WordPositionContainer $container,
        WordPositionContainer $referenceContainer,
        array                 $wordReductionRatios,
        ResultSet             $resultSet,
        bool                  $isTitle,
    ): void {
        $pairsDistance = $container->compareWith($referenceContainer);
        foreach ($pairsDistance as $pairDistance) {
            [$word1, $word2, $distance] = $pairDistance;
            $weight = self::neighbourWeight($distance);
            if (isset($wordReductionRatios[$word1])) {
                $weight *= $wordReductionRatios[$word1];
            }

            if (isset($wordReductionRatios[$word2])) {
                $weight *= $wordReductionRatios[$word2];
            }

            if ($isTitle) {
                $resultSet->addTitleNeighbourWeight($word1, $word2, $id, $weight, $distance);
            } else {
                $resultSet->addNeighbourWeight($word1, $word2, $id, $weight, $distance);
            }
        }
    }
}
