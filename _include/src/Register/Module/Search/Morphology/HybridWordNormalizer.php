<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Morphology;

use S2\Rose\Stemmer\StemmerInterface;
use S2\Rose\Stemmer\WordNormalizerInterface;

/** Uses OpenCorpora lemmas for known Russian words and Porter for everything else. */
final class HybridWordNormalizer implements WordNormalizerInterface
{
    private const int CACHE_LIMIT = 8192;

    /** @var array<string, non-empty-list<string>> */
    private array $cache = [];

    public function __construct(
        private readonly OpenCorporaDictionary $dictionary,
        private readonly StemmerInterface      $fallbackStemmer,
    ) {
    }

    #[\Override]
    public function stemWord(string $word, bool $normalize = true): string
    {
        // Preserve deterministic legacy behavior for consumers unaware of multi-form normalization.
        return $this->fallbackStemmer->stemWord($word, $normalize);
    }

    #[\Override]
    public function normalizeWord(string $word, bool $normalize = true): array
    {
        $normalizedWord = $normalize ? mb_strtolower($word) : $word;
        if (isset($this->cache[$normalizedWord])) {
            return $this->cache[$normalizedWord];
        }

        if (preg_match('/^[а-яё]+$/Du', $normalizedWord) === 1) {
            $normalForms = $this->dictionary->normalForms($normalizedWord);
            if ($normalForms !== []) {
                return $this->cache($normalizedWord, $normalForms);
            }
        }

        $stem = $this->fallbackStemmer->stemWord($word, $normalize);

        return $this->cache($normalizedWord, [$stem]);
    }

    /**
     * @param non-empty-list<string> $normalForms
     * @return non-empty-list<string>
     */
    private function cache(string $word, array $normalForms): array
    {
        if (\count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }

        return $this->cache[$word] = $normalForms;
    }
}
