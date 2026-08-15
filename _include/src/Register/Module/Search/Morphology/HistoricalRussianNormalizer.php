<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Morphology;

/** Produces a dictionary-validated modern spelling for historical Russian text. */
final class HistoricalRussianNormalizer
{
    private const int CACHE_LIMIT = 8192;

    /** @var array<string, array{spelling: string, normalForms: list<string>}> */
    private array $cache = [];

    public function __construct(
        private readonly ChurchSlavonicNormalizer   $churchSlavonicNormalizer,
        private readonly PreReformRussianNormalizer $preReformNormalizer,
        private readonly OpenCorporaDictionary      $dictionary,
    ) {
    }

    public function normalizeWord(string $word, bool $normalize = true): string
    {
        return $this->analyze($word, $normalize)['spelling'];
    }

    /** @return list<string> */
    public function normalForms(string $word, bool $normalize = true): array
    {
        return $this->analyze($word, $normalize)['normalForms'];
    }

    public function normalizeText(string $text, bool $normalize = true): string
    {
        return preg_replace_callback(
            '/[\p{L}\p{M}]+/u',
            fn(array $matches): string => $this->normalizeWord($matches[0], $normalize),
            $text,
        ) ?? ($normalize ? mb_strtolower($text) : $text);
    }

    /** @return array{spelling: string, normalForms: list<string>} */
    private function analyze(string $word, bool $normalize): array
    {
        $normalizedWord = $normalize ? mb_strtolower($word) : $word;
        $cacheKey       = ($normalize ? '1:' : '0:') . $normalizedWord;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $churchSlavonicWord = $this->churchSlavonicNormalizer->normalize($normalizedWord);
        $canonicalWord      = $this->preReformNormalizer->normalize($churchSlavonicWord);
        $normalForms        = [];
        $spelling           = $canonicalWord;

        if (preg_match('/^[а-яё]+$/Du', $canonicalWord) === 1) {
            $normalForms = $this->dictionary->normalForms($canonicalWord);

            if ($normalForms === []) {
                foreach ($this->preReformNormalizer->modernAlternatives($churchSlavonicWord) as $alternative) {
                    $alternativeNormalForms = $this->dictionary->normalForms($alternative);
                    if ($alternativeNormalForms === []) {
                        continue;
                    }

                    if ($spelling === $canonicalWord) {
                        $spelling = $alternative;
                    }

                    array_push($normalForms, ...$alternativeNormalForms);
                }
            }
        }

        $analysis = [
            'spelling'    => $spelling,
            'normalForms' => array_values(array_unique($normalForms)),
        ];

        if (\count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }

        return $this->cache[$cacheKey] = $analysis;
    }
}
