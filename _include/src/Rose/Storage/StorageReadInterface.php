<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage;

use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\TocEntryWithMetadata;
use Register\Rose\Storage\Dto\SnippetResult;
use Register\Rose\Storage\Dto\SnippetQuery;

interface StorageReadInterface
{
    /**
     * @param string[] $words
     */
    public function fulltextResultByWords(array $words, ?int $instanceId): FulltextIndexContent;

    public function isExcludedWord(string $word): bool;

    /**
     * @return TocEntryWithMetadata[]
     */
    public function getTocByExternalIds(ExternalIdCollection $externalIds): array;

    public function getSnippets(SnippetQuery $snippetQuery): SnippetResult;

    public function getTocSize(?int $instanceId): int;
}
