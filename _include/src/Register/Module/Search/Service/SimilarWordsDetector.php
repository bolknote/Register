<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use S2\Rose\Stemmer\StemmerInterface;

readonly class SimilarWordsDetector
{
    public function __construct(private StemmerInterface $stemmer)
    {
    }

    /**
     * @param array<mixed> $otherWords
     */
    public function wordIsSimilarToOtherWords(string $word, array $otherWords): bool
    {
        $checkingWords = explode(' ', $word);

        foreach ($checkingWords as $wordToCheck) {
            if (mb_strlen($wordToCheck) < 3) {
                continue;
            }

            $stemToCheck = $this->stemmer->stemWord($wordToCheck);
            foreach ($otherWords as $otherWord) {
                if ($otherWord === $stemToCheck || (str_starts_with($stemToCheck, $otherWord) && mb_strlen($otherWord) >= 5)) {
                    return true;
                }
            }
        }

        return false;
    }
}
