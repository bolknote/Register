<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Module\Search\Service;

/** Generates one insertion, deletion, substitution or adjacent transposition in Unicode. */
final class SingleEditVariants
{
    /** @return list<string> */
    public function generate(string $word): array
    {
        $word = str_replace('ё', 'е', mb_strtolower($word));
        $alphabet = match (true) {
            preg_match('/\A[a-z]{4,24}\z/', $word) === 1 => str_split('abcdefghijklmnopqrstuvwxyz'),
            preg_match('/\A[а-я]{4,24}\z/u', $word) === 1 => preg_split('//u', 'абвгдежзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY),
            default => [],
        };
        if ($alphabet === [] || $alphabet === false) {
            return [];
        }

        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return [];
        }

        $variants = [];
        foreach ($characters as $position => $character) {
            $prefix = mb_substr($word, 0, $position);
            $suffix = mb_substr($word, $position);
            $tail = mb_substr($word, $position + 1);
            $next = $characters[$position + 1] ?? null;
            if ($next !== null && $next !== $character) {
                $variants[$prefix . $next . $character . mb_substr($word, $position + 2)] = true;
            }

            $variants[$prefix . $tail] = true;
            foreach ($alphabet as $letter) {
                $variants[$prefix . $letter . $suffix] = true;
                if ($letter !== $character) {
                    $variants[$prefix . $letter . $tail] = true;
                }
            }
        }

        foreach ($alphabet as $letter) {
            $variants[$word . $letter] = true;
        }

        unset($variants[$word]);

        return array_keys($variants);
    }
}
