<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Storage;

use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\ExternalIdCollection;
use S2\Rose\Entity\Metadata\ImgCollection;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\TocEntry;
use S2\Rose\Entity\TocEntryWithMetadata;
use S2\Rose\Exception\LogicException;
use S2\Rose\Exception\UnknownIdException;
use S2\Rose\Finder;
use S2\Rose\Storage\Dto\SnippetQuery;
use S2\Rose\Storage\Dto\SnippetResult;

abstract class ArrayStorage implements StorageReadInterface, StorageWriteInterface
{
    /** @var array<int|string, int> */
    protected array $excludedWords = [];

    /** @var array<int, array{wordCount?: int, images?: ImgCollection, snippets?: list<SnippetSource>}> */
    protected array $metadata = [];

    /**
     * @var array<string, TocEntry>
     */
    protected array $toc = [];

    protected FulltextProxyInterface $fulltextProxy;

    /** @var array<int, ExternalId> */
    protected array $externalIdMap = [];

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function fulltextResultByWords(array $words, ?int $instanceId): FulltextIndexContent
    {
        $result = new FulltextIndexContent();
        foreach ($words as $word) {
            $data = $this->fulltextProxy->getByWord($word);
            foreach ($data as $id => $positionsByType) {
                $externalId = $this->externalIdFromInternalId($id);
                if (!$externalId instanceof \S2\Rose\Entity\ExternalId) {
                    continue;
                }

                if ($instanceId === null || $externalId->getInstanceId() === $instanceId) {
                    $serializedExtId = $externalId->toString();
                    $result->add($word, new FulltextIndexPositionBag(
                        $externalId,
                        $positionsByType[FulltextProxyInterface::TYPE_TITLE] ?? [],
                        $positionsByType[FulltextProxyInterface::TYPE_KEYWORD] ?? [],
                        $positionsByType[FulltextProxyInterface::TYPE_CONTENT] ?? [],
                        $this->metadata[$id]['wordCount'] ?? 0,
                        isset($this->toc[$serializedExtId]) ? $this->toc[$serializedExtId]->getRelevanceRatio() : 1.0
                    ));
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     * @throws UnknownIdException
     */
    #[\Override]
    public function getSnippets(SnippetQuery $snippetQuery): SnippetResult
    {
        $result = new SnippetResult();
        $snippetQuery->iterate(function (ExternalId $externalId, ?array $positions) use ($result): void {
            $fallbackCount = 0;
            foreach ($this->metadata[$this->internalIdFromExternalId($externalId)]['snippets'] ?? [] as $snippetSource) {
                if ($fallbackCount < 2 || $snippetSource->coversOneOfPositions($positions ?? [])) {
                    $result->attach($externalId, $snippetSource);
                    ++$fallbackCount;
                }
            }
        });

        return $result;
    }

    /**
     * {@inheritdoc}
     * @throws UnknownIdException
     */
    #[\Override]
    public function addToFulltextIndex(array $titleWords, array $keywords, array $contentWords, ExternalId $externalId): void
    {
        $id = $this->internalIdFromExternalId($externalId);
        foreach ($titleWords as $position => $word) {
            $this->fulltextProxy->addWord($word, $id, FulltextProxyInterface::TYPE_TITLE, (int)$position);
        }

        foreach ($keywords as $position => $word) {
            $this->fulltextProxy->addWord($word, $id, FulltextProxyInterface::TYPE_KEYWORD, (int)$position);
        }

        foreach ($contentWords as $position => $word) {
            $this->fulltextProxy->addWord($word, $id, FulltextProxyInterface::TYPE_CONTENT, (int)$position);
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function isExcludedWord(string $word): bool
    {
        return isset($this->excludedWords[$word]);
    }

    /**
     * Drops frequent words from index.
     */
    public function cleanup(): void
    {
        $threshold = Finder::fulltextRateExcludeNum(\count($this->toc));

        foreach (array_keys($this->fulltextProxy->getFrequentWords($threshold)) as $word) {
            // Drop fulltext frequent or empty items
            $this->fulltextProxy->removeWord((string)$word);
            $this->excludedWords[$word] = 1;
        }
    }

    /**
     * {@inheritdoc}
     * @throws UnknownIdException
     */
    #[\Override]
    public function removeFromIndex(ExternalId $externalId): void
    {
        $internalId = $this->internalIdFromExternalId($externalId);

        $this->fulltextProxy->removeById($internalId);

        unset($this->metadata[$internalId]);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function addEntryToToc(TocEntry $entry, ExternalId $externalId): void
    {
        try {
            $internalId = $this->internalIdFromExternalId($externalId);
            $this->removeFromToc($externalId);
        } catch (UnknownIdException) {
            $internalId = 0;
            foreach ($this->toc as $existingEntry) {
                $internalId = max($internalId, $existingEntry->getInternalId() ?? 0);
            }

            ++$internalId;
        }

        $entry->setInternalId($internalId);

        $this->toc[$externalId->toString()] = $entry;
        $this->externalIdMap[$internalId]   = $externalId;
    }

    /**
     * {@inheritdoc}
     * @throws UnknownIdException
     */
    #[\Override]
    public function addMetadata(ExternalId $externalId, int $wordCount, ImgCollection $imgCollection): void
    {
        $internalId                               = $this->internalIdFromExternalId($externalId);
        $this->metadata[$internalId]['wordCount'] = $wordCount;
        $this->metadata[$internalId]['images']    = $imgCollection;
    }

    /**
     * @throws UnknownIdException
     */
    #[\Override]
    public function addSnippets(ExternalId $externalId, SnippetSource ...$snippets): void
    {
        if (\count($snippets) === 0) {
            return;
        }

        $this->metadata[$this->internalIdFromExternalId($externalId)]['snippets'] = array_values($snippets);
    }

    /**
     * {@inheritdoc}
     * @return \S2\Rose\Entity\TocEntryWithMetadata[]
     */
    #[\Override]
    public function getTocByExternalIds(ExternalIdCollection $externalIds): array
    {
        $result = [];
        foreach ($externalIds->toArray() as $externalId) {
            $serializedExtId = $externalId->toString();
            if (isset($this->toc[$serializedExtId])) {
                $internalId = $this->toc[$serializedExtId]->getInternalId();
                if ($internalId === null) {
                    throw new LogicException(sprintf('TOC entry "%s" has no internal id.', $serializedExtId));
                }

                $result[] = new TocEntryWithMetadata(
                    $this->toc[$serializedExtId],
                    $externalId,
                    $this->metadata[$internalId]['images'] ?? new ImgCollection()
                );
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getTocByExternalId(ExternalId $externalId): ?TocEntry
    {
        $serializedExtId = $externalId->toString();

        return $this->toc[$serializedExtId] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function removeFromToc(ExternalId $externalId): void
    {
        $serializedExtId = $externalId->toString();
        if (!isset($this->toc[$serializedExtId])) {
            return;
        }

        $internalId = $this->toc[$serializedExtId]->getInternalId();
        if ($internalId !== null) {
            unset($this->externalIdMap[$internalId]);
        }

        unset($this->toc[$serializedExtId]);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getTocSize(?int $instanceId): int
    {
        return \count($this->toc);
    }

    /**
     * @throws UnknownIdException
     */
    private function internalIdFromExternalId(ExternalId $externalId): int
    {
        $serializedExtId = $externalId->toString();
        if (!isset($this->toc[$serializedExtId])) {
            throw UnknownIdException::createIndexMissingExternalId($externalId);
        }

        $internalId = $this->toc[$serializedExtId]->getInternalId();
        if ($internalId === null) {
            throw new LogicException(sprintf('TOC entry "%s" has no internal id.', $serializedExtId));
        }

        return $internalId;
    }

    private function externalIdFromInternalId(int $internalId): ?ExternalId
    {
        return $this->externalIdMap[$internalId] ?? null;
    }
}
