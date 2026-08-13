<?php
/**
 * Library for Russian typography
 *
 * Converts '""' quotation marks to '«»' and '„“' and puts non-breaking space
 * characters according to Russian typography conventions.
 *
 * @copyright 2010-2024 Roman Parpalak, partially based on code (C) by Dmitry Smirnov
 * @see       http://spectator.ru/technology/php/quotation_marks_stike_back
 * @license   MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace s2_extensions\s2_typo;

class Typograph
{
    public static function processRussianText(string $contents, bool $soft = false): string
    {
        $nbsp = $soft ? "\xc2\xa0" : '&nbsp;';

        $savedSubstrings = [];
        $i               = 0;

        // Extract sensitive data
        $contents = preg_replace_callback('#<(script|style|textarea|pre|code|kbd|title).*?</\\1>|\\$\\$[^<]*?\\$\\$#s', static function (array $matches) use (&$savedSubstrings, &$i): string {
            $savedSubstrings[$i] = $matches[0];
            return '<¬' . ($i++) . '¬>';
        }, $contents) ?? throw new \RuntimeException('Unable to protect typography-sensitive markup.');

        $contents = preg_replace_callback('#<[^>\-"]*+[\-"][^>]*+>#', static function (array $matches) use (&$savedSubstrings, &$i): string {
            $savedSubstrings[$i] = $matches[0];
            return '<¬' . ($i++) . '¬>';
        }, $contents) ?? throw new \RuntimeException('Unable to protect typography-sensitive attributes.');

        $contents = "\n" . str_replace('&quot;', '"', $contents);

        // Quotation marks
        $quotationMarksRegex = '#(?<=[(\s">]|^)"([^"]*[^\s"(])"#S';

        $contents = preg_replace($quotationMarksRegex, '«\\1»', $contents)
            ?? throw new \RuntimeException('Unable to replace quotation marks.');

        // Nested quotation marks
        if (str_contains($contents, '"')) {
            $contents = preg_replace($quotationMarksRegex, '«\\1»', $contents)
                ?? throw new \RuntimeException('Unable to replace nested quotation marks.');
            while (true) {
                /**
                 * This regex is a logical equivalent of '#«([^«»]*+)«([^»]*+)»#u'.
                 * Since the 'u' modifier is a bit slower, there are some optimizations here.
                 *
                 * '[^«»]' stands for any bytes that are not in byte representation of "«»".
                 * The other bytes that do not form '«' or '»' together are matched with lookahead '(?!«|»).'.
                 *
                 * @see https://www.rexegg.com/regex-quantifiers.html#explicit_greed for optimization tips
                 */
                $previousContents = $contents;
                $contents = preg_replace('#«((?:[^«»]++|(?!«|»).)*+)«((?:[^»]++|(?!»).)*+)»#', '«\\1„\\2“', $contents)
                    ?? throw new \RuntimeException('Unable to normalize nested quotation marks.');
                if ($contents === $previousContents) {
                    break;
                }
            }
        }

        $replace  = [
            // Some special chars
            '...'  => '…',
            '(tm)' => '™',
            '(TM)' => '™',
            '(c)'  => '©',
            '(C)'  => '©',

            // '-' to em-dash
            "\n- " => "\n— ",
            ' - '  => $nbsp . '— ',
            ' – '  => $nbsp . '— ', // en dash
            ' — '  => $nbsp . '— ',
            '>- '  => '>— ',
        ];
        $contents = preg_replace_callback(
            '# - | – | — |>- |\(tm\)|\(c\)|\n- |\.{3}#i',
            static fn($matches): string => $replace[$matches[0]] ?? $matches[0],
            $contents
        ) ?? throw new \RuntimeException('Unable to normalize typography symbols.');

        if (!$soft) {
            /**
             * @note Quite a general regex. In case of bugs try a more restricted one
             *
             * $contents = preg_replace('~
             * (^|\s|\(|>|«)      # Start matching at the beginning of words
             * \K                 # Reset match (faster than lookbehind)
             * (?!«)              # Reset match (faster than lookbehind)
             * [^\-\s<>()\\\\]++  # First word part
             * -                  # Match a hyphen
             * [^\s<>()\\\\]+     # Second word part
             * ~x',               # Use the 'x' modifier to enable whitespace and comments in the pattern
             * '<nobr>\\0</nobr>',
             * $contents
             * );
             */
            $contents = preg_replace_callback('~
                [^\s<>\-]++ # First word part
                -           # Match a hyphen
                [^\s<]+     # Second word part
                ~x',
                static fn(array $matches): string => mb_strlen($matches[0]) < 40 ? '<nobr>' . $matches[0] . '</nobr>' : $matches[0],
                $contents
            ) ?? throw new \RuntimeException('Unable to protect hyphenated words.');
        }

        // Prepositions and particles
        $contents = preg_replace(
            '#\s++(?=(?:ли|ль|же|ж|бы|б)[ .!?,;):])|(Не|Ни|Но|По|Ко|К|За|Со|С|У|Из|И|А|О|Об|От|До|В|Во|На|(?<=[ (]|' . $nbsp . ')(?:к|с|у|и|а|о|в))\K\s+#',
            $nbsp,
            $contents
        ) ?? throw new \RuntimeException('Unable to normalize spaces around short words.');

        // Put sensitive data back
        if (\count($savedSubstrings) > 0) {
            $contents = preg_replace_callback('#<¬(\d+)¬>#S', static function ($matches) use ($savedSubstrings): string {
                $result = $savedSubstrings[$matches[1]] ?? $matches[0];
                unset($savedSubstrings[$matches[1]]);

                return $result;
            }, $contents) ?? throw new \RuntimeException('Unable to restore protected typography fragments.');
        }

        // Move quotation marks outside links
        $contents = preg_replace(
            '#<a ([^>]*)>\\s*«([^<]*?)»\\s*</a>#s',
            '«<a \\1>\\2</a>»',
            $contents
        ) ?? throw new \RuntimeException('Unable to move quotation marks outside links.');

        return trim($contents);
    }
}
