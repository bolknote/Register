<?php
/**
 * @copyright 2020-2026 Roman Parpalak
 * @license   MIT
 */

declare(strict_types = 1);

namespace Register\Rose\Stemmer;

abstract class AbstractStemmer
{
    public function __construct(protected ?StemmerInterface $nextStemmer = null)
    {
    }
}
