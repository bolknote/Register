<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Storage;

/** Optional vocabulary lookups without loading full-text positions. */
interface IndexWordLookupInterface
{
    /**
     * Only words with active postings in the requested instance are returned.
     *
     * @param list<string> $words
     * @return list<string>
     */
    public function findExistingIndexWords(array $words, ?int $instanceId = null, int $limit = 128): array;
}
