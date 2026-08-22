<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Rose\Stemmer\StemmerHelper;
use Register\Rose\Stemmer\StemmerInterface;

final class SimilarWordsDetector
{
    private const int CACHE_LIMIT = 1024;

    /** @var array<string, list<string>> */
    private array $formCache = [];

    public function __construct(private readonly StemmerInterface $stemmer)
    {
    }

    /**
     * @param array<mixed> $otherWords
     */
    public function wordIsSimilarToOtherWords(string $word, array $otherWords): bool
    {
        $checkingForms = $this->forms($word);
        foreach ($otherWords as $otherWord) {
            if (!\is_string($otherWord)) {
                continue;
            }

            foreach ($this->forms($otherWord) as $otherForm) {
                foreach ($checkingForms as $checkingForm) {
                    if (
                        $otherForm === $checkingForm
                        || (
                            min(mb_strlen($checkingForm), mb_strlen($otherForm)) >= 5
                            && (
                                str_starts_with($checkingForm, $otherForm)
                                || str_starts_with($otherForm, $checkingForm)
                            )
                        )
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function forms(string $text): array
    {
        if (isset($this->formCache[$text])) {
            return $this->formCache[$text];
        }

        $tokens = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $forms  = [];
        if (\is_array($tokens)) {
            foreach ($tokens as $token) {
                $token = mb_strtolower($token);
                if (mb_strlen($token) < 3) {
                    continue;
                }

                $forms[$token] = $token;
                foreach (StemmerHelper::stemWords($this->stemmer, $token) as $stem) {
                    $stem = mb_strtolower($stem);
                    if (mb_strlen($stem) >= 3) {
                        $forms[$stem] = $stem;
                    }
                }
            }
        }

        if (\count($this->formCache) >= self::CACHE_LIMIT) {
            $this->formCache = [];
        }

        return $this->formCache[$text] = array_values($forms);
    }
}
