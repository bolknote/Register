<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Stemmer;

/**
 * A stemmer which can return several equivalent search forms for one word.
 */
interface WordNormalizerInterface extends StemmerInterface
{
    /**
     * All returned words occupy the same logical position in the search index.
     *
     * @return non-empty-list<string>
     */
    public function normalizeWord(string $word, bool $normalize = true): array;
}
