<?php

declare(strict_types = 1);

/**
 * Fulltext search
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose;

use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\FulltextQuery;
use Register\Rose\Entity\FulltextResult;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultSet;
use Register\Rose\Exception\ImmutableException;
use Register\Rose\Exception\LogicException;
use Register\Rose\Exception\UnknownIdException;
use Register\Rose\Snippet\SnippetBuilder;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Dto\SnippetQuery;
use Register\Rose\Storage\StorageReadInterface;

/**
 * @see \Register\Rose\Test\FinderTest
 */
class Finder
{
    protected ?string $highlightTemplate = null;

    protected ?string $snippetLineSeparator = null;

    /**
     * @var list<string>
     */
    protected array $highlightMaskRegexArray = [];

    public function __construct(protected StorageReadInterface $storage, protected StemmerInterface $stemmer)
    {
    }

    /** @param list<string> $highlightMaskRegexArray */
    public function setHighlightMaskRegexArray(array $highlightMaskRegexArray): self
    {
        $this->highlightMaskRegexArray = $highlightMaskRegexArray;

        return $this;
    }

    public function setHighlightTemplate(string $highlightTemplate): self
    {
        $this->highlightTemplate = $highlightTemplate;

        return $this;
    }

    public function setSnippetLineSeparator(string $snippetLineSeparator): self
    {
        $this->snippetLineSeparator = $snippetLineSeparator;

        return $this;
    }

    /**
     * @throws ImmutableException
     */
    public function find(Query $query, bool $isDebug = false): ResultSet
    {
        $resultSet = new ResultSet($query->getLimit(), $query->getOffset(), $isDebug);
        if ($this->highlightTemplate !== null) {
            $resultSet->setHighlightTemplate($this->highlightTemplate);
        }

        $rawWords = $query->valueToArray();
        $resultSet->addProfilePoint('Input cleanup');

        if (\count($rawWords) > 0) {
            $this->findFulltext($rawWords, $query->getInstanceId(), $resultSet);
            $resultSet->addProfilePoint('Fulltext search');
        }

        $resultSet->freeze();

        $sortedExternalIds = $resultSet->getSortedExternalIds();

        $resultSet->addProfilePoint('Sort results');

        foreach ($this->storage->getTocByExternalIds($sortedExternalIds) as $tocEntryWithExternalId) {
            $resultSet->attachToc($tocEntryWithExternalId);
        }

        $resultSet->addProfilePoint('Fetch TOC');

        $relevanceByExternalIds = $resultSet->getSortedRelevanceByExternalId();
        if (\count($relevanceByExternalIds) > 0) {
            $this->buildSnippets($relevanceByExternalIds, $resultSet);
        }

        return $resultSet;
    }

    /**
     * Ignore frequent words encountering in indexed items.
     */
    public static function fulltextRateExcludeNum(int $tocSize): int
    {
        return max((int)ceil((float)$tocSize * 0.5), 20);
    }

    /**
     * @param list<string> $words
     *
     * @throws ImmutableException
     */
    protected function findFulltext(array $words, ?int $instanceId, ResultSet $resultSet): void
    {
        $fulltextQuery        = new FulltextQuery($words, $this->stemmer);
        $fulltextIndexContent = $this->storage->fulltextResultByWords($fulltextQuery->getWordsWithStems(), $instanceId);
        $fulltextResult       = new FulltextResult(
            $fulltextQuery,
            $fulltextIndexContent,
            $this->storage->getTocSize($instanceId)
        );

        $fulltextResult->fillResultSet($resultSet);
    }

    /** @param array<string, float|int> $relevanceByExternalIds */
    public function buildSnippets(array $relevanceByExternalIds, ResultSet $resultSet): void
    {
        $snippetQuery = new SnippetQuery(ExternalIdCollection::fromStringArray(array_keys($relevanceByExternalIds)));
        try {
            $foundWordPositionsByExternalId = $resultSet->getFoundWordPositionsByExternalId();
        } catch (ImmutableException $e) {
            throw new LogicException($e->getMessage(), 0, $e);
        }

        foreach ($foundWordPositionsByExternalId as $serializedExtId => $wordsInfo) {
            if (!isset($relevanceByExternalIds[$serializedExtId])) {
                // Out of limit and offset scope, no need to fetch snippets.
                continue;
            }

            $externalId   = ExternalId::fromString($serializedExtId);
            $allPositions = array_merge(...array_values($wordsInfo));
            $snippetQuery->attach($externalId, $allPositions);
        }

        $resultSet->addProfilePoint('Snippets: make query');

        $snippetResult = $this->storage->getSnippets($snippetQuery);

        $resultSet->addProfilePoint('Snippets: obtaining');

        $sb = new SnippetBuilder($this->stemmer, $this->snippetLineSeparator);
        $sb->setHighlightMaskRegexArray($this->highlightMaskRegexArray);
        try {
            $sb->attachSnippets($resultSet, $snippetResult);
        } catch (ImmutableException|UnknownIdException $e) {
            throw new LogicException($e->getMessage(), 0, $e);
        }

        $resultSet->addProfilePoint('Snippets: building');
    }
}
