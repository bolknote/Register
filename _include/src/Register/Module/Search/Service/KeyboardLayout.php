<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Module\Search\Service;

/** Produces an optional QWERTY / Russian keyboard-layout alternative. */
final class KeyboardLayout
{
    /** @return list<string> */
    public function alternatives(string $query): array
    {
        $letters = preg_match_all('/[a-zа-яё]/iu', $query);
        if ($letters === false || $letters < 4) {
            return [];
        }

        $latin = preg_match('/[a-z]/i', $query) === 1;
        $russian = preg_match('/[а-яё]/iu', $query) === 1;
        if ($latin === $russian) {
            return [];
        }

        $englishKeys = str_split("qwertyuiop[]asdfghjkl;'zxcvbnm,.`");
        $russianKeys = preg_split('//u', 'йцукенгшщзхъфывапролджэячсмитьбюё', -1, PREG_SPLIT_NO_EMPTY);
        if ($russianKeys === false) {
            return [];
        }

        $mapping = [];
        foreach ($englishKeys as $position => $key) {
            $letter = $russianKeys[$position];
            $mapping[$latin ? $key : $letter] = $latin ? $letter : $key;
            if (preg_match('/[a-z]/', $key) === 1) {
                $mapping[$latin ? strtoupper($key) : mb_strtoupper($letter)] = $latin ? mb_strtoupper($letter) : strtoupper($key);
            }
        }

        // Punctuation inside a mistyped word can represent a letter, while
        // delimiters outside words (including search quotes) retain their meaning.
        $alternative = $latin
            ? preg_replace_callback("/[a-z](?:[a-z\\[\\];',.`]*[a-z])?/i", static fn(array $token): string => strtr($token[0], $mapping), $query)
            : strtr($query, $mapping);

        return $alternative !== null ? [$alternative] : [];
    }
}
