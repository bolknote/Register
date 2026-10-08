<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2026 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

use Register\Rose\Exception\ImmutableException;
use Register\Rose\Exception\UnknownIdException;
use Register\Rose\Helper\ProfileHelper;

/**
 * @see \Register\Rose\Test\Entity\ResultSetTest
 */
class ResultSet
{
    protected float $startedAt = 0.0;

    /** @var array<string, array<string, float>> */
    protected array $data = [];

    /**
     * @var list<array<string, string|float|int>>
     */
    protected array $profilePoints = [];

    protected bool $isFrozen = false;

    /**
     * @var array<string, ResultItem>
     */
    protected array $items = [];

    /**
     * Result cache
     */
    /** @var array<string, float>|null */
    protected ?array $sortedRelevance = null;

    /**
     * Positions of found words
     */
    /** @var array<string, array<string, list<int>>> */
    protected array $positions = [];

    /** @var array<string, array<string, true>> */
    protected array $exactMatches = [];

    /** @var array<string, array<int, true>> */
    protected array $queryTermMatches = [];

    /** @var array<string, int|null> */
    private array $rankingDates = [];

    private bool $rankingDatesAvailable = false;

    protected string $highlightTemplate = '<i>%s</i>';

    protected ResultTrace $trace;

    public function __construct(protected ?int $limit = null, protected int $offset = 0, protected bool $isDebug = false)
    {
        $this->offset = max(0, $offset);
        if ($this->isDebug) {
            $this->startedAt = microtime(true);
        }

        $this->trace = new ResultTrace();
    }

    public function addProfilePoint(string $message): void
    {
        if (!$this->isDebug) {
            return;
        }

        $this->profilePoints[] = ProfileHelper::getProfilePoint($message, -$this->startedAt + ($this->startedAt = microtime(true)));
    }

    /** @return list<array<string, string|float|int>> */
    public function getProfilePoints(): array
    {
        $this->isFrozen = true;

        return $this->profilePoints;
    }

    public function setHighlightTemplate(string $highlightTemplate): self
    {
        $this->highlightTemplate = $highlightTemplate;

        return $this;
    }

    public function getHighlightTemplate(): string
    {
        return $this->highlightTemplate;
    }

    /**
     * @param array<string, float|int> $weights
     * @param list<int>                $positions
     *
     * @throws ImmutableException
     */
    public function addWordWeight(string $word, ExternalId $externalId, array $weights, array $positions = []): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $serializedExtId = $externalId->toString();

        $weight = array_product($weights);

        if (!isset($this->data[$serializedExtId][$word])) {
            $this->data[$serializedExtId][$word]      = $weight;
            $this->positions[$serializedExtId][$word] = $positions;
        } else {
            $this->data[$serializedExtId][$word]      += $weight;
            $this->positions[$serializedExtId][$word] = $this->mergePositions($this->positions[$serializedExtId][$word], $positions);
        }

        if ($positions === []) {
            $this->trace->addKeywordWeight($word, $serializedExtId, $weights);
        } else {
            $this->trace->addWordWeight($word, $serializedExtId, $weights, $positions);
        }
    }

    /**
     * @param list<int> $positions
     * @throws ImmutableException
     */
    public function addExactMatch(string $word, ExternalId $externalId, array $positions = [], bool $countForRanking = true): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $serializedExtId = $externalId->toString();

        if ($countForRanking) {
            $this->exactMatches[$serializedExtId][$word] = true;
        }

        $this->data[$serializedExtId]['*exact_' . $word] = 0.0;
        $this->positions[$serializedExtId][$word] = $this->mergePositions($this->positions[$serializedExtId][$word] ?? [], $positions);

        $this->trace->addExactMatch($word, $serializedExtId);
    }

    /** @throws ImmutableException */
    public function addQueryTermMatch(int $position, ExternalId $externalId): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $this->queryTermMatches[$externalId->toString()][$position] = true;
    }

    /** Filters required constraints before freezing, counting and pagination. */
    public function retainExternalIds(ExternalIdCollection $externalIds): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $allowed = [];
        foreach ($externalIds->toArray() as $externalId) {
            $allowed[$externalId->toString()] = true;
        }

        $this->data = array_intersect_key($this->data, $allowed);
        $this->positions = array_intersect_key($this->positions, $allowed);
        $this->exactMatches = array_intersect_key($this->exactMatches, $allowed);
        $this->queryTermMatches = array_intersect_key($this->queryTermMatches, $allowed);
        $this->items = array_intersect_key($this->items, $allowed);
        $this->rankingDates = array_intersect_key($this->rankingDates, $allowed);
    }

    /** @param array<string, int|null> $dates */
    public function setRankingDates(array $dates): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $this->rankingDates = $dates;
        $this->rankingDatesAvailable = true;
    }

    public function hasRankingDates(): bool
    {
        return $this->rankingDatesAvailable;
    }

    /** @throws ImmutableException */
    public function addNeighbourWeight(string $word1, string $word2, ExternalId $externalId, float $weight, int $distance): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $serializedExtId = $externalId->toString();

        $this->data[$serializedExtId]['*n_' . $word1 . '_' . $word2] = $weight;

        $this->trace->addNeighbourWeight($word1, $word2, $serializedExtId, $weight, $distance);
    }

    /** @throws ImmutableException */
    public function addTitleNeighbourWeight(string $word1, string $word2, ExternalId $externalId, float $weight, int $distance): void
    {
        if ($this->isFrozen) {
            throw new ImmutableException('One cannot mutate a search result after obtaining its content.');
        }

        $serializedExtId = $externalId->toString();

        $this->data[$serializedExtId]['*tn_' . $word1 . '_' . $word2] = $weight;

        $this->trace->addTitleNeighbourWeight($word1, $word2, $serializedExtId, $weight, $distance);
    }

    /**
     * @return array<string, float|int>
     *
     * @throws ImmutableException
     */
    public function getSortedRelevanceByExternalId(): array
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot read a result before freezing it.');
        }

        if ($this->sortedRelevance !== null) {
            return $this->sortedRelevance;
        }

        $this->sortedRelevance = [];
        foreach ($this->data as $serializedExtId => $stat) {
            $relevance = array_sum($stat);
            $this->sortedRelevance[$serializedExtId] = $relevance;
        }

        // Prefer complete queries before exact inflections of partial queries.
        uksort($this->sortedRelevance, function (string $left, string $right): int {
            $coverageOrder = $this->queryTermMatchCount($right) <=> $this->queryTermMatchCount($left);
            if ($coverageOrder !== 0) {
                return $coverageOrder;
            }

            $exactOrder = $this->exactMatchCount($right) <=> $this->exactMatchCount($left);
            if ($exactOrder !== 0) {
                return $exactOrder;
            }

            $relevanceOrder = ($this->sortedRelevance[$right] ?? 0.0)
                <=> ($this->sortedRelevance[$left] ?? 0.0);
            if ($relevanceOrder !== 0) {
                return $relevanceOrder;
            }

            $dateOrder = ($this->rankingDates[$right] ?? PHP_INT_MIN) <=> ($this->rankingDates[$left] ?? PHP_INT_MIN);

            return $dateOrder !== 0 ? $dateOrder : strcmp($left, $right);
        });

        if ($this->offset > 0 || $this->limit > 0) {
            $this->sortedRelevance = \array_slice(
                $this->sortedRelevance,
                $this->offset,
                $this->limit > 0 ? $this->limit : null,
                true,
            );
        }

        return $this->sortedRelevance;
    }

    /**
     * @return array<string, array<string, list<int>>>
     *
     * @throws ImmutableException
     */
    public function getFoundWordPositionsByExternalId(): array
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot read a result before freezing it.');
        }

        return $this->positions;
    }

    /**
     * Finishes the process of building the ResultSet.
     */
    public function freeze(): self
    {
        $this->isFrozen = true;

        return $this;
    }

    public function attachToc(TocEntryWithMetadata $tocEntryWithExternalId): void
    {
        $tocEntry   = $tocEntryWithExternalId->getTocEntry();
        $externalId = $tocEntryWithExternalId->getExternalId();

        // Legacy/custom storage may supply dates only with TOC metadata. Once
        // ordering is published, later metadata cannot change page membership.
        if ($this->sortedRelevance === null) {
            $this->rankingDates[$externalId->toString()] = $tocEntry->getDate()?->getTimestamp();
            $this->rankingDatesAvailable = true;
        }

        $this->items[$externalId->toString()] = new ResultItem(
            $externalId->getId(),
            $externalId->getInstanceId(),
            $tocEntry->getTitle(),
            $tocEntry->getDescription(),
            $tocEntry->getDate(),
            $tocEntry->getUrl(),
            $tocEntry->getRelevanceRatio(),
            $tocEntryWithExternalId->getImgCollection(),
            $this->highlightTemplate
        );
    }

    /**
     * @throws UnknownIdException
     */
    public function attachSnippet(ExternalId $externalId, Snippet $snippet): void
    {
        $serializedExtId = $externalId->toString();
        if (!isset($this->items[$serializedExtId])) {
            throw UnknownIdException::createResultMissingExternalId($externalId);
        }

        $this->items[$serializedExtId]->setSnippet($snippet);
    }

    /**
     * @return list<ResultItem>
     * @throws ImmutableException
     */
    public function getItems(): array
    {
        $relevanceArray = $this->getSortedRelevanceByExternalId();

        $foundWords = $this->getFoundWordPositionsByExternalId();

        $result          = [];
        foreach ($relevanceArray as $serializedExtId => $relevance) {
            $resultItem = $this->items[$serializedExtId];
            $resultItem
                ->setRelevance($relevance)
                ->setFoundWords(array_keys($foundWords[$serializedExtId] ?? []))
            ;
            $result[]          = $resultItem;
        }

        return $result;
    }

    /**
     * @throws ImmutableException
     */
    public function getFoundExternalIds(): ExternalIdCollection
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot read a result before freezing it.');
        }

        return ExternalIdCollection::fromStringArray(array_keys($this->data));
    }

    /**
     * @throws ImmutableException
     */
    public function getSortedExternalIds(): ExternalIdCollection
    {
        return ExternalIdCollection::fromStringArray(array_keys($this->getSortedRelevanceByExternalId()));
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws UnknownIdException
     * @throws ImmutableException
     */
    public function getTrace(): array
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot obtain a trace before freezing the result set.');
        }

        $traceArray     = $this->trace->toArray();
        $relevanceArray = $this->getSortedRelevanceByExternalId();

        $result = [];
        foreach ($relevanceArray as $serializedExtId => $relevance) {
            if (!isset($this->items[$serializedExtId])) {
                throw UnknownIdException::createResultMissingExternalId(ExternalId::fromString($serializedExtId));
            }

            $result[$serializedExtId] = [
                'title'     => $this->items[$serializedExtId]->getTitle(),
                'relevance' => $relevance,
            ];

            $result[$serializedExtId]['externalRelevanceRatio'] = $this->items[$serializedExtId]->getRelevanceRatio();
            $result[$serializedExtId]['matchedQueryTerms'] = $this->queryTermMatchCount($serializedExtId);
            $result[$serializedExtId]['exactQueryTerms'] = $this->exactMatchCount($serializedExtId);

            $result[$serializedExtId]['trace'] = $traceArray[$serializedExtId];
        }

        return $result;
    }

    /**
     * @throws ImmutableException
     */
    public function getTotalCount(): int
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot obtain a trace before freezing the result set.');
        }

        return count($this->data);
    }

    /** Highest query coverage over all results, including those outside the current page. */
    public function getMaxMatchedQueryTerms(): int
    {
        if (!$this->isFrozen) {
            throw new ImmutableException('One cannot read a result before freezing it.');
        }

        $max = 0;
        foreach (array_keys($this->data) as $id) {
            $max = max($max, $this->queryTermMatchCount($id));
        }

        return $max;
    }

    /**
     * @return array<string, float|int>
     *
     * @throws UnknownIdException
     */
    public function getRelevanceByStemsFromId(ExternalId $externalId): array
    {
        $serializedExtId = $externalId->toString();
        if (!isset($this->data[$serializedExtId])) {
            throw UnknownIdException::createResultMissingExternalId($externalId);
        }

        return $this->data[$serializedExtId];
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     * @return list<int>
     */
    private function mergePositions(array $left, array $right): array
    {
        $positions = array_values(array_unique(array_merge($left, $right)));
        sort($positions);

        return $positions;
    }

    private function exactMatchCount(string $serializedExtId): int
    {
        return \count($this->exactMatches[$serializedExtId] ?? []);
    }

    private function queryTermMatchCount(string $serializedExtId): int
    {
        return \count($this->queryTermMatches[$serializedExtId] ?? []);
    }
}
