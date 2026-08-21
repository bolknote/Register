<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2026 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity;

use S2\Rose\Exception\ImmutableException;
use S2\Rose\Exception\UnknownIdException;
use S2\Rose\Helper\ProfileHelper;

/**
 * @see \S2\Rose\Test\Entity\ResultSetTest
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

    protected string $highlightTemplate = '<i>%s</i>';

    protected ResultTrace $trace;

    public function __construct(protected ?int $limit = null, protected int $offset = 0, protected bool $isDebug = false)
    {
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
            $this->positions[$serializedExtId][$word] = array_merge($this->positions[$serializedExtId][$word], $positions);
        }

        if ($positions === []) {
            $this->trace->addKeywordWeight($word, $serializedExtId, $weights);
        } else {
            $this->trace->addWordWeight($word, $serializedExtId, $weights, $positions);
        }
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

        // Order by relevance
        arsort($this->sortedRelevance);

        if ($this->limit > 0) {
            $this->sortedRelevance = \array_slice(
                $this->sortedRelevance,
                $this->offset,
                $this->limit
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
        $relevanceResult = [];
        $dateResult      = [];
        foreach ($relevanceArray as $serializedExtId => $relevance) {
            $resultItem = $this->items[$serializedExtId];
            $resultItem
                ->setRelevance($relevance)
                ->setFoundWords(array_keys($foundWords[$serializedExtId] ?? []))
            ;
            $result[]          = $resultItem;
            $relevanceResult[] = $relevance;
            $date              = $resultItem->getDate();
            $dateResult[]      = $date !== null ? $date->getTimestamp() : 0;
        }

        array_multisort($relevanceResult, SORT_DESC, $dateResult, SORT_DESC, $result);

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
}
