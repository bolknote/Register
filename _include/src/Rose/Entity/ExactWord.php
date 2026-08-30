<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

/** Encodes source word forms separately from their normalized search forms. */
final class ExactWord
{
    public const string PREFIX = ':exact:';

    public static function encode(string $word): string
    {
        return self::PREFIX . str_replace('ё', 'е', mb_strtolower($word));
    }

    public static function decode(string $word): ?string
    {
        if (!str_starts_with($word, self::PREFIX)) {
            return null;
        }

        $decoded = substr($word, \strlen(self::PREFIX));

        return $decoded !== '' ? $decoded : null;
    }
}
