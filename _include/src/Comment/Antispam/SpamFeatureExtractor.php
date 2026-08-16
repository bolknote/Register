<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final class SpamFeatureExtractor
{
    public const int SENTENCE_LIKE_LATIN_TRANSLITERATION_THRESHOLD = 53;

    /** @var array<string, true> */
    private const array STRONG_TRANSLITERATED_RUSSIAN_WORDS = [
        'budet' => true,
        'byl' => true,
        'byla' => true,
        'byli' => true,
        'bylo' => true,
        'cherez' => true,
        'chto' => true,
        'chtoby' => true,
        'davai' => true,
        'davay' => true,
        'dlya' => true,
        'esli' => true,
        'eta' => true,
        'eti' => true,
        'eto' => true,
        'etot' => true,
        'ego' => true,
        'gde' => true,
        'hotya' => true,
        'kak' => true,
        'kogda' => true,
        'kotoraya' => true,
        'kotoryi' => true,
        'kotoryy' => true,
        'kuda' => true,
        'luchshe' => true,
        'mne' => true,
        'mozhno' => true,
        'nado' => true,
        'ona' => true,
        'oni' => true,
        'otkuda' => true,
        'posle' => true,
        'potom' => true,
        'prosto' => true,
        'sebe' => true,
        'sebya' => true,
        'tak' => true,
        'tebe' => true,
        'tolko' => true,
        'ty' => true,
        'uzhe' => true,
        'vot' => true,
        'vse' => true,
        'vsegda' => true,
        'vsyo' => true,
        'ya' => true,
        'zhe' => true,
    ];

    /** @var array<string, true> */
    private const array WEAK_TRANSLITERATED_RUSSIAN_WORDS = [
        'a' => true,
        'i' => true,
        'k' => true,
        'o' => true,
        's' => true,
        'u' => true,
        'v' => true,
    ];

    /** @var list<string> */
    private const array TRANSLITERATED_RUSSIAN_STEMS = [
        'bezopas',
        'dolzh',
        'isprav',
        'komment',
        'kotor',
        'mozh',
        'nauch',
        'nazyva',
        'ostalo',
        'polucha',
        'pochem',
        'pravdo',
        'rabota',
        'segod',
        'seych',
        'skolko',
        'udivlya',
        'zaby',
    ];

    /**
     * @return list<string>
     */
    public function domains(string $text): array
    {
        $matchCount = preg_match_all('#https?://[^\s<>\]\[()"\']+#iu', $text, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $domains = [];
        foreach ($matches[0] as $url) {
            $host = parse_url(rtrim($url, '.,;:!?'), PHP_URL_HOST);
            if (\is_string($host) && $host !== '') {
                $domains[] = trim(mb_strtolower($host), '.');
            }
        }

        return array_values(array_unique($domains));
    }

    public function linkCount(string $text): int
    {
        $count = preg_match_all('#(https?://\S{2,}?)(?=[\s),\'<>\]]|&lt;|&gt;|[.;:](?:\s|$)|$)#u', $text);

        return $count !== false ? $count : 0;
    }

    public function hasHtml(string $text): bool
    {
        return preg_match('#</?[a-z][^>]*>#i', $text) === 1;
    }

    public function hasFormattingControls(string $text): bool
    {
        return preg_match('#\p{Cf}#u', $text) === 1;
    }

    public function hasLongRepetition(string $text): bool
    {
        return preg_match('#(.)\1{9,}#us', $text) === 1;
    }

    /**
     * Detects a spam campaign that splits one Russian sentence transliterated
     * into Latin letters between the author-name and comment fields. Addresses
     * are deliberately ignored: the campaign generates a new email each time.
     */
    public function hasSentenceLikeLatinTransliteration(string $name, string $text): bool
    {
        return $this->sentenceLikeLatinTransliterationScore($name, $text)
            >= self::SENTENCE_LIKE_LATIN_TRANSLITERATION_THRESHOLD;
    }

    public function sentenceLikeLatinTransliterationScore(string $name, string $text): int
    {
        $name = trim($name);
        $text = trim($text);
        if ($text === '' || mb_strlen($name) < 6 || mb_strlen($name) > 80) {
            return 0;
        }

        // A genuine identifier can contain these characters. The campaign puts
        // a lowercase fragment of prose into the name field instead.
        if (preg_match('#[./()<>@_]#', $name) === 1 || preg_match('#[A-Z]#', $name) === 1) {
            return 0;
        }

        $nameWords = $this->asciiWords($name);
        $textWords = $this->asciiWords($text);
        if (\count($nameWords) < 2 || \count($nameWords) > 10 || $textWords === []) {
            return 0;
        }

        // Do not classify Cyrillic, Greek, or any other script as this very
        // specific Latin-transliteration campaign.
        $nonAsciiLetters = preg_replace('#[A-Za-z]#', '', $name . ' ' . $text);
        if (!\is_string($nonAsciiLetters) || preg_match('#\p{L}#u', $nonAsciiLetters) === 1) {
            return 0;
        }

        $score = 35;
        if (\count($nameWords) >= 3) {
            $score += 10;
        }

        $score += min(40, $this->transliteratedRussianScore([...$nameWords, ...$textWords]));
        if ($this->transliteratedRussianScore($nameWords) >= 7) {
            $score += 10;
        }
        if (\count($textWords) <= 12) {
            $score += 5;
        }

        return $score;
    }

    /** @return list<string> */
    private function asciiWords(string $text): array
    {
        $count = preg_match_all('#[a-z]+#i', $text, $matches);
        if ($count === false || $count === 0) {
            return [];
        }

        return array_map(strtolower(...), $matches[0]);
    }

    /** @param list<string> $words */
    private function transliteratedRussianScore(array $words): int
    {
        $score = 0;
        foreach ($words as $word) {
            if (isset(self::STRONG_TRANSLITERATED_RUSSIAN_WORDS[$word])) {
                $score += 4;
            } elseif (isset(self::WEAK_TRANSLITERATED_RUSSIAN_WORDS[$word])) {
                ++$score;
            }

            foreach (self::TRANSLITERATED_RUSSIAN_STEMS as $stem) {
                if (str_starts_with($word, $stem)) {
                    $score += 3;
                    break;
                }
            }

            if (preg_match('#(?:skiy|ckiy|tsya|sya|nyy|nyi|aya|yaya|ogo|omu|ymi|ami|akh|yah|uyu)$#', $word) === 1) {
                $score += 3;
            }
            if (preg_match('#(?:shch|tsya|sya|zh|kh)#', $word) === 1) {
                $score += 2;
            }
        }

        return $score;
    }
}
