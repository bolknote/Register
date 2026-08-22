<?php
/**
 * @copyright 2020-2024 Roman Parpalak
 * @license   MIT
 */

declare(strict_types = 1);

namespace Register\Rose\Helper;

/**
 * @see \Register\Rose\Test\Helper\StringHelperTest
 */
class StringHelper
{
    public const string BOLD        = 'b';

    public const string ITALIC      = 'i';

    public const string SUPERSCRIPT = 'u';

    public const string SUBSCRIPT   = 'd';

    public const string FORMATTING_SYMBOLS = self::BOLD . self::ITALIC . self::SUPERSCRIPT . self::SUBSCRIPT;

    // Characters that split the word into components
    public const string WORD_COMPONENT_DELIMITERS = '-.,';

    /** @param list<string> $words */
    public static function removeLongWords(array &$words): void
    {
        $words = array_values(array_filter($words, static function (string $word): bool {
            $len = mb_strlen($word);

            return $len <= 100 && $len !== 0;
        }));
    }

    /**
     * @return list<string>
     */
    public static function sentencesFromText(string $text, bool $hasFormatting): array
    {
        $text2 = preg_replace('#(\p{Lu}\p{L}*\.?)\s+(\p{Lu}\p{L}?\.)\s+(\p{Lu})#u', "\\1�\\2�\\3", $text) ?? $text;
        $text2 = preg_replace('#(\p{Lu}\p{L}?\.)(\p{Lu}\p{L}?\.)\s+(\p{Lu})#u', "\\1\\2�\\3", $text2) ?? $text2;
        $text2 = preg_replace('#\s\K(Mr.|Dr.)\s(?=\p{Lu}\p{L}?)#u', "\\1�\\3", $text2) ?? $text2;

        $substrings = preg_split('#(?:\.|[?!][»"]?)\K([ \n\t\r]+)(?=(?:[\p{Pd}-]\s)?[^\p{Ll}])#Su', $text2);
        if ($substrings === false) {
            return [];
        }

        $substrings = array_map(
            static fn(string $substring): string => str_replace("�", ' ', $substring),
            $substrings
        );

        if ($hasFormatting) {
            // We keep the formatting scope through several sentences.
            //
            // For example, consider the input: 'Sentence <i>1. Sentence 2. Sentence</i> 3.'
            // After processing, it becomes ['Sentence <i>1.</i>', '<i>Sentence 2.</i>', '<i>Sentence</i> 3.'].
            $tagsFromPrevSentence = [];
            foreach ($substrings as $index => $substring) {
                foreach (array_reverse($tagsFromPrevSentence) as $possibleTag => $num) {
                    if ($num > 0) {
                        $substring                          = str_repeat('\\' . $possibleTag, $num) . $substring;
                        $tagsFromPrevSentence[$possibleTag] = 0;
                    }
                }

                $substrings[$index] = self::fixUnbalancedInternalFormatting($substring, $tagsFromPrevSentence);
            }
        }

        return $substrings;
    }

    /**
     * @return list<string>
     */
    public static function sentencesFromCode(string $text): array
    {
        $substrings = preg_split('#(\r?\n\r?){1,}#Su', $text);
        if ($substrings === false) {
            return [];
        }

        return array_map(trim(...), $substrings);
    }

    public static function convertInternalFormattingToHtml(string $text): string
    {
        return strtr($text, [
            '\\\\'                               => '\\',
            '\\' . self::BOLD                    => '<b>',
            '\\' . strtoupper(self::BOLD)        => '</b>',
            '\\' . self::ITALIC                  => '<i>',
            '\\' . strtoupper(self::ITALIC)      => '</i>',
            '\\' . self::SUBSCRIPT               => '<sub>',
            '\\' . strtoupper(self::SUBSCRIPT)   => '</sub>',
            '\\' . self::SUPERSCRIPT             => '<sup>',
            '\\' . strtoupper(self::SUPERSCRIPT) => '</sup>',
        ]);
    }

    public static function clearInternalFormatting(string $text): string
    {
        return strtr($text, [
            '\\\\'                               => '\\',
            '\\' . self::BOLD                    => '',
            '\\' . strtoupper(self::BOLD)        => '',
            '\\' . self::ITALIC                  => '',
            '\\' . strtoupper(self::ITALIC)      => '',
            '\\' . self::SUBSCRIPT               => '',
            '\\' . strtoupper(self::SUBSCRIPT)   => '',
            '\\' . self::SUPERSCRIPT             => '',
            '\\' . strtoupper(self::SUPERSCRIPT) => '',
        ]);
    }

    /**
     * @Note: This approach with counting formatting symbols gives wrong results for the same nested tags.
     * For example, for '\i 1 \b 2 \i 3' it returns '\i 1 \b 2 \i 3 \B\I\I', however '\i 1 \b 2 \i 3\I\B\I' is expected.
     * It's ok since nesting of formatting tags like <i>a<i>b</i></i> do not make a lot of sense.
     * @param array<string, int> $tagsNum
     */
    public static function fixUnbalancedInternalFormatting(string $text, array &$tagsNum): string
    {
        preg_match_all('#\\\\(?:\\\\(*SKIP)\\\\)*\K[' . self::FORMATTING_SYMBOLS . ']#i', $text, $matches);

        foreach ($matches[0] as $match) {
            $lowerMatch           = strtolower($match);
            $tagsNum[$lowerMatch] = ($tagsNum[$lowerMatch] ?? 0) + ($match === $lowerMatch ? 1 : -1);
        }

        $result = $text;

        foreach ($tagsNum as $possibleTag => $num) {
            if ($num < 0) {
                $result = str_repeat('\\' . $possibleTag, -$num) . $result;
            }
        }

        foreach (array_reverse($tagsNum) as $possibleTag => $num) {
            if ($num > 0) {
                $result .= str_repeat('\\' . strtoupper($possibleTag), $num);
            }
        }

        return $result;
    }

    /**
     * @return array{0: array<string>, 1: array<string>}
     */
    public static function getUnbalancedInternalFormatting(string $text): array
    {
        preg_match_all('#\\\\(?:\\\\(*SKIP)\\\\)*\K[' . self::FORMATTING_SYMBOLS . ']#i', $text, $matches);

        $openStack  = [];
        $closeStack = [];

        foreach ($matches[0] as $match) {
            $lowerMatch = strtolower($match);
            if ($match === $lowerMatch) {
                $openStack[] = $match;
                continue;
            }

            $found = false;
            for ($i = \count($openStack) - 1; $i >= 0; --$i) {
                if ($openStack[$i] === $lowerMatch) {
                    array_splice($openStack, $i, 1);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $closeStack[] = $match;
            }
        }

        return [$openStack, $closeStack];
    }
}
