<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

/**
 * Produces deliberately anonymous text features for the site-local classifier.
 *
 * The model never stores words or fragments from moderated comments. Features
 * are reduced to keyed short hashes before they leave this class.
 */
final class SpamTextFeatureExtractor
{
    private const int MAX_WORDS = 48;

    private const int MAX_CHARACTER_SOURCE_LENGTH = 240;

    private const int MAX_FEATURES = 512;

    /** @return list<string> Keyed, non-reversible identifiers used by the persisted model. */
    public function hashes(string $name, string $text, string $salt): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $salt) !== 1) {
            throw new \InvalidArgumentException('The spam text-model salt must contain 16 hexadecimal bytes.');
        }

        $key = hex2bin($salt);
        if (!\is_string($key) || \strlen($key) !== SODIUM_CRYPTO_SHORTHASH_KEYBYTES) {
            throw new \InvalidArgumentException('The spam text-model salt cannot be decoded.');
        }

        $hashes = [];
        foreach ($this->features($name, $text) as $feature) {
            $hash = sodium_crypto_shorthash($feature, $key);
            $id = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
            $hashes[$id] = true;
        }

        $result = array_keys($hashes);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Raw features exist only while training and are never persisted.
     *
     * @return list<string>
     */
    public function features(string $name, string $text): array
    {
        $name = $this->normalize($name);
        $text = $this->normalize($text);
        $nameWords = $this->words($name);
        $textWords = $this->words($text);
        $features = [];

        $this->addWordFeatures($features, 'n:', $nameWords);
        $this->addWordFeatures($features, 't:', $textWords);
        $this->addBigramFeatures($features, 'nb:', $nameWords);
        $this->addBigramFeatures($features, 'tb:', $textWords);

        $allWords = array_slice([...$nameWords, '__body__', ...$textWords], 0, self::MAX_WORDS * 2 + 1);
        $this->addBigramFeatures($features, 'ab:', $allWords);

        $combined = trim($name . ' ' . $text);
        if ($combined !== '') {
            $features['len:' . $this->lengthBucket(mb_strlen($combined))] = true;
            $features['name-words:' . min(8, count($nameWords))] = true;
            $features['text-words:' . $this->wordCountBucket(count($textWords))] = true;
            $features['script:' . $this->scriptClass($combined)] = true;
        }

        $characterSource = mb_substr(implode(' ', $allWords), 0, self::MAX_CHARACTER_SOURCE_LENGTH);
        $characters = preg_split('//u', $characterSource, -1, PREG_SPLIT_NO_EMPTY);
        if (\is_array($characters)) {
            $characterCount = count($characters);
            foreach ([3, 4] as $width) {
                for ($offset = 0; $offset + $width <= $characterCount; ++$offset) {
                    $features['c' . $width . ':' . implode('', array_slice($characters, $offset, $width))] = true;
                    if (count($features) >= self::MAX_FEATURES) {
                        break 2;
                    }
                }
            }
        }

        return array_slice(array_keys($features), 0, self::MAX_FEATURES);
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value);
        $value = preg_replace('~https?://[^\s<>]+~u', ' __url__ ', $value) ?? $value;
        $value = preg_replace('/\d+/u', '0', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    private function words(string $value): array
    {
        $count = preg_match_all('/[\p{L}\p{N}_]{2,40}/u', $value, $matches);
        if ($count === false || $count === 0) {
            return [];
        }

        return array_slice($matches[0], 0, self::MAX_WORDS);
    }

    /**
     * @param array<string, true> $features
     * @param list<string> $words
     */
    private function addWordFeatures(array &$features, string $prefix, array $words): void
    {
        foreach ($words as $word) {
            $features[$prefix . $word] = true;
        }
    }

    /**
     * @param array<string, true> $features
     * @param list<string> $words
     */
    private function addBigramFeatures(array &$features, string $prefix, array $words): void
    {
        $wordCount = count($words);
        for ($i = 1; $i < $wordCount; ++$i) {
            $features[$prefix . $words[$i - 1] . ' ' . $words[$i]] = true;
        }
    }

    private function lengthBucket(int $length): string
    {
        return match (true) {
            $length < 20  => 'xs',
            $length < 60  => 's',
            $length < 160 => 'm',
            $length < 500 => 'l',
            default       => 'xl',
        };
    }

    private function wordCountBucket(int $count): string
    {
        return match (true) {
            $count < 3  => 'xs',
            $count < 8  => 's',
            $count < 24 => 'm',
            $count < 64 => 'l',
            default     => 'xl',
        };
    }

    private function scriptClass(string $value): string
    {
        $latin = preg_match_all('/[a-z]/iu', $value);
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $value);
        $latin = $latin === false ? 0 : $latin;
        $cyrillic = $cyrillic === false ? 0 : $cyrillic;

        return match (true) {
            $latin > 0 && $cyrillic > 0 => 'mixed',
            $latin > 0                  => 'latin',
            $cyrillic > 0               => 'cyrillic',
            default                     => 'other',
        };
    }
}
