<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Extractor;

use Register\Rose\Extractor\HtmlDom\DomExtractor;
use Register\Rose\Extractor\HtmlRegex\RegexExtractor;

class DefaultExtractorFactory
{
    public static function create(): ChainExtractor
    {
        $extractor = new ChainExtractor();
        if (DomExtractor::available()) {
            $extractor->attachExtractor(new DomExtractor());
        }

        $extractor->attachExtractor(new RegexExtractor());

        return $extractor;
    }
}
