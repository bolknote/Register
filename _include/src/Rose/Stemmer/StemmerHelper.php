<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Stemmer;

final class StemmerHelper
{
    /**
     * @return non-empty-list<string>
     */
    public static function stemWords(StemmerInterface $stemmer, string $word, bool $normalize = true): array
    {
        if (!$stemmer instanceof WordNormalizerInterface) {
            return [$stemmer->stemWord($word, $normalize)];
        }

        return array_values(array_unique($stemmer->normalizeWord($word, $normalize)));
    }
}
