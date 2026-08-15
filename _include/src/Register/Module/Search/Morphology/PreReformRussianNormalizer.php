<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Morphology;

/** Converts unambiguous pre-1918 Russian spellings to modern search spellings. */
final class PreReformRussianNormalizer
{
    private const array LETTER_REPLACEMENTS = [
        'Ѣ' => 'Е',
        'ѣ' => 'е',
        'І' => 'И',
        'і' => 'и',
        'Ѳ' => 'Ф',
        'ѳ' => 'ф',
        'Ѵ' => 'И',
        'ѵ' => 'и',
        'Ѷ' => 'И',
        'ѷ' => 'и',
    ];

    private const array WHOLE_WORD_ALTERNATIVES = [
        'онѣ'    => 'они',
        'однѣ'   => 'одни',
        'однѣхъ' => 'одних',
    ];

    public function normalize(string $word): string
    {
        $word = preg_replace_callback(
            '/[\p{L}\p{M}]+/u',
            static function (array $matches): string {
                $token     = $matches[0];
                $baseToken = preg_replace('/\p{M}+/u', '', $token) ?? $token;
                if (
                    preg_match('/\p{Cyrillic}/u', $baseToken) !== 1
                    || preg_match('/^(?:\p{Cyrillic}|[iI])+$/u', $baseToken) !== 1
                ) {
                    return $token;
                }

                // OCR commonly confuses decimal «і» with visually identical Latin i/I.
                return strtr($token, ['I' => 'И', 'i' => 'и']);
            },
            $word,
        ) ?? $word;

        $word = strtr($word, self::LETTER_REPLACEMENTS);
        if (!str_contains($word, 'ъ') && !str_contains($word, 'Ъ')) {
            return $word;
        }

        // The hard sign disappeared at the end of words and parts of hyphenated compounds,
        // but remains a separator inside modern spellings such as «подъезд».
        return preg_replace('/(?<=[а-яё])ъ(?=$|[-.,])/iu', '', $word) ?? $word;
    }

    /**
     * Returns context-sensitive reform candidates that must be checked against a modern dictionary.
     *
     * @return list<string>
     */
    public function modernAlternatives(string $word): array
    {
        $canonical = $this->normalize($word);
        $result    = [];

        if (isset(self::WHOLE_WORD_ALTERNATIVES[$word])) {
            $result[] = self::WHOLE_WORD_ALTERNATIVES[$word];
        }

        if (preg_match('/[жчшщ]аго$/u', $canonical) === 1) {
            $result[] = substr($canonical, 0, -strlen('аго')) . 'его';
        } elseif (str_ends_with($canonical, 'аго')) {
            $result[] = substr($canonical, 0, -strlen('аго')) . 'ого';
        } elseif (str_ends_with($canonical, 'яго')) {
            $result[] = substr($canonical, 0, -strlen('яго')) . 'его';
        }

        if (str_ends_with($canonical, 'ыя')) {
            $result[] = substr($canonical, 0, -strlen('ыя')) . 'ые';
        }

        // Test the original spelling: after replacing «і», nouns such as «Россія» and the
        // adjectival ending «-ія» both end in «-ия» and can only be separated by the dictionary.
        if (str_ends_with($word, 'ія')) {
            $result[] = substr($canonical, 0, -strlen('ия')) . 'ие';
        }

        return array_values(array_unique(array_filter(
            $result,
            static fn(string $candidate): bool => $candidate !== $canonical,
        )));
    }
}
