<?php
/**
 * @copyright 2011-2024 Roman Parpalak
 * @license   MIT
 */

declare(strict_types = 1);

namespace S2\Rose\Snippet;

use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\ResultSet;
use S2\Rose\Entity\Snippet;
use S2\Rose\Entity\SnippetLine;
use S2\Rose\Exception\ImmutableException;
use S2\Rose\Exception\UnknownIdException;
use S2\Rose\Stemmer\StemmerInterface;
use S2\Rose\Storage\Dto\SnippetResult;

class SnippetBuilder
{
    /**
     * @var list<string>
     */
    protected array $highlightMaskRegexArray = [];

    public function __construct(protected StemmerInterface $stemmer, protected ?string $snippetLineSeparator = null)
    {
    }

    /** @param list<string> $regexes */
    public function setHighlightMaskRegexArray(array $regexes): self
    {
        $this->highlightMaskRegexArray = $regexes;

        return $this;
    }

    /**
     * @throws ImmutableException
     * @throws UnknownIdException
     */
    public function attachSnippets(ResultSet $result, SnippetResult $snippetResult): self
    {
        $foundWords = $result->getFoundWordPositionsByExternalId();

        $snippetResult->iterate(function (ExternalId $externalId, SnippetSource ...$snippets) use ($foundWords, $result): void {
            $snippet = $this->buildSnippet(
                $foundWords[$externalId->toString()],
                $result->getHighlightTemplate(),
                $result->getRelevanceByStemsFromId($externalId),
                ...$snippets
            );
            $result->attachSnippet($externalId, $snippet);
        });

        return $this;
    }

    /**
     * @param array<string, list<int>>  $foundPositionsByStems
     * @param array<string, float|int> $relevanceByStems
     */
    public function buildSnippet(array $foundPositionsByStems, string $highlightTemplate, array $relevanceByStems, SnippetSource ...$snippetSources): Snippet
    {
        // Stems of the words found in the $id chapter
        $stems            = [];
        $foundWordNum     = 0;
        $snippetRelevance = [];
        foreach ($foundPositionsByStems as $stem => $positions) {
            if ($positions === []) {
                //  Not a fulltext search result (e.g. title from single keywords)
                continue;
            }

            $stems[] = $stem;
            ++$foundWordNum;
            foreach ($snippetSources as $snippetIndex => $snippetSource) {
                if ($snippetSource->coversOneOfPositions($positions)) {
                    $snippetRelevance[$snippetIndex] = ($snippetRelevance[$snippetIndex] ?? 0.0)
                        + (float)($relevanceByStems[$stem] ?? 0.0);
                }
            }
        }

        $introSnippetLines = array_map(
            SnippetLine::createFromSnippetSourceWithoutFoundWords(...),
            \array_slice($snippetSources, 0, 2)
        );

        $snippet = new Snippet($highlightTemplate, ...$introSnippetLines);

        if ($this->snippetLineSeparator !== null) {
            $snippet->setLineSeparator($this->snippetLineSeparator);
        }

        if ($foundWordNum === 0) {
            return $snippet;
        }

        foreach ($snippetSources as $snippetIndex => $snippetSource) {
            if (!isset($snippetRelevance[$snippetIndex])) {
                continue;
            }

            $snippetLine = new SnippetLine(
                $snippetSource->getText(),
                $snippetSource->getFormatId(),
                $this->stemmer,
                $stems,
                $snippetRelevance[$snippetIndex]
            );
            $snippetLine->setMaskRegexArray($this->highlightMaskRegexArray);

            $snippet->attachSnippetLine($snippetSource->getMinPosition(), $snippetSource->getMaxPosition(), $snippetLine);
        }

        return $snippet;
    }
}
