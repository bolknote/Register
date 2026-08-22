<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Extractor;

interface ExtractorInterface
{
    /**
     * Extract various data from the text being indexed.
     */
    public function extract(string $text): ExtractionResult;
}
