<?php
/**
 * @copyright 2017-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 */

declare(strict_types = 1);

namespace S2\Rose\Entity;

use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Exception\RuntimeException;
use S2\Rose\Helper\SnippetTextHelper;
use S2\Rose\Helper\StringHelper;
use S2\Rose\Stemmer\StemmerHelper;
use S2\Rose\Stemmer\StemmerInterface;

/**
 * @see \S2\Rose\Test\Entity\SnippetLineTest
 */
class SnippetLine
{
    protected ?string $lineWithoutMaskedFragments = null;

    /**
     * @var string[]
     */
    protected array $maskedFragments = [];

    /**
     * @var string[]
     */
    private array $maskRegexArray = [];

    private ?HighlightIntervals $highlightIntervals = null;

    /**
     * @var string[]
     */
    private array $foundStems = [];

    /** @param list<string> $stemsFoundSomewhere */
    public function __construct(
        protected string $line,
        protected int $formatId,
        protected StemmerInterface $stemmer,
        /**
         * @var string[]
         */
        protected array $stemsFoundSomewhere,
        protected float $relevance
    )
    {
    }

    public static function createFromSnippetSourceWithoutFoundWords(SnippetSource $snippetSource): self
    {
        return new self(
            $snippetSource->getText(),
            $snippetSource->getFormatId(),
            new class implements StemmerInterface {
                #[\Override]
                public function stemWord(string $word, bool $normalize = true): string
                {
                    return $word;
                }
            },
            [],
            0
        );
    }

    public function getRelevance(): float
    {
        return $this->relevance;
    }

    /**
     * @return string[]
     * @deprecated Not used anymore. TODO delete if not needed
     */
    public function getFoundStems(): array
    {
        $this->parse();

        return $this->foundStems;
    }

    public function getLine(): string
    {
        return $this->line;
    }

    public function getFormatId(): int
    {
        return $this->formatId;
    }

    /**
     * @throws RuntimeException
     */
    public function getHighlighted(string $highlightTemplate, bool $includeFormatting): string
    {
        if (!str_contains($highlightTemplate, '%s')) {
            throw new RuntimeException('Highlight template must contain "%s" substring for sprintf() function.');
        }

        $highlightIntervals = $this->parse();

        $line = $this->getLineWithoutMaskedFragments();

        $replacedLine      = '';
        $processedPosition = 0;
        foreach ($highlightIntervals->toArray() as [$start, $end]) {
            $replacedLine  .= substr($line, $processedPosition, $start - $processedPosition);
            $lineToReplace = substr($line, $start, $end - $start);

            [$openFormatting, $closeFormatting] = StringHelper::getUnbalancedInternalFormatting($lineToReplace);

            // Open formatting goes to the end
            $outsidePostfix = implode('', array_map(static fn(string $char): string => '\\' . $char, $openFormatting));
            $insidePostfix  = implode('', array_map(static fn(string $char): string => '\\' . strtoupper($char), array_reverse($openFormatting)));

            // Close formatting goes to the start
            $outsidePrefix = implode('', array_map(static fn(string $char): string => '\\' . $char, $closeFormatting));
            $insidePrefix  = implode('', array_map(static fn(string $char): string => '\\' . strtolower($char), array_reverse($closeFormatting)));

            $replacedLine .= $outsidePrefix . sprintf(
                    $highlightTemplate, $insidePrefix . $lineToReplace . $insidePostfix
                ) . $outsidePostfix;

            $processedPosition = $end;
        }

        $replacedLine .= substr($line, $processedPosition);

        $result = $this->restoreMaskedFragments($replacedLine);

        return SnippetTextHelper::convertFormatting($result, $this->formatId, $includeFormatting);
    }

    /** @param list<string> $regexes */
    public function setMaskRegexArray(array $regexes): void
    {
        $this->maskRegexArray = $regexes;
    }

    protected function parse(): HighlightIntervals
    {
        if ($this->highlightIntervals instanceof \S2\Rose\Entity\HighlightIntervals) {
            // Already parsed
            return $this->highlightIntervals;
        }

        $this->highlightIntervals = new HighlightIntervals();

        $line = $this->getLineWithoutMaskedFragments();

        if (\count($this->stemsFoundSomewhere) === 0) {
            return $this->highlightIntervals;
        }

        if ($this->formatId === SnippetSource::FORMAT_INTERNAL) {
            $regex = '/(?x)
            [\\d\\p{L}^_]*(?:(?:\\\\[' . StringHelper::FORMATTING_SYMBOLS . '])+[\\d\\p{L}^_]*)* # matches as many word and formatting characters as possible
            (*SKIP) # do not cross this line on backtracking
            \\K # restart pattern matching to the end of the word.
            (?: # delimiter regex which includes:
                [^\\\\\\d\\p{L}^_\\-.,] # non-word character
                |[\\-.,]+(?![\\d\\p{L}\\-.,]) # [,-.] followed by a non-word character
                |\\\\(?:[' . StringHelper::FORMATTING_SYMBOLS . '](?![\\d\\p{L}\\-.,])|\\\\) # formatting sequence followed by a non-word character or escaped backslash
            )+/iu';
        } else {
            $regex = '/(?x)
            [\\d\\p{L}^_]* # matches as many word and formatting characters as possible
            (*SKIP) # do not cross this line on backtracking
            \\K # restart pattern matching to the end of the word.
            (?: # delimiter regex which includes:
                [^\\d\\p{L}^_\\-.,] # non-word character
                |[\\-.,]+(?![\\d\\p{L}\\-.,]) # [,-.] followed by a non-word character
            )+/iu';
        }

        $wordArray = $this->splitWithOffsets($regex, $line);

        $flippedStems = [];
        foreach ($this->stemsFoundSomewhere as $stem) {
            $flippedStems[$stem] = 1;
        }

        foreach ($wordArray as [$rawWord, $offset]) {
            $word = $this->formatId === SnippetSource::FORMAT_INTERNAL ? StringHelper::clearInternalFormatting($rawWord) : $rawWord;
            $word = str_replace(SnippetTextHelper::STORE_MARKER, '', $word);

            if ($word === '') {
                // No need to call $intervals->skipInterval() since regex may work several times on a single delimiter
                continue;
            }

            $stem = $this->findMatchingStem($word, $flippedStems);
            if ($stem !== null) {
                $this->highlightIntervals->addInterval($offset, $offset + \strlen($rawWord));
                $this->foundStems[] = $stem;
            } else {
                // Word is not found. Check if it is like a hyphenated compound word, e.g. 'test-drive' or 'long-term'
                if (false !== strpbrk($word, StringHelper::WORD_COMPONENT_DELIMITERS)) {
                    // Here is more simple regex since formatting sequences may be present.
                    // The downside is appearance of empty words, but they are filtered out later.
                    $subWordArray = $this->splitWithOffsets('#[\-.,]+#u', $rawWord);

                    foreach ($subWordArray as [$rawSubWord, $subOffset]) {
                        $subWord = $this->formatId === SnippetSource::FORMAT_INTERNAL ? StringHelper::clearInternalFormatting($rawSubWord) : $rawSubWord;
                        $subWord = str_replace(SnippetTextHelper::STORE_MARKER, '', $subWord);

                        if ($rawSubWord === '') {
                            continue;
                        }

                        $subStem = $this->findMatchingStem($subWord, $flippedStems);
                        if ($subStem !== null) {
                            $this->highlightIntervals->addInterval($offset + $subOffset, $offset + $subOffset + \strlen($rawSubWord));
                            $this->foundStems[] = $subStem;
                        } else {
                            $this->highlightIntervals->skipInterval();
                        }
                    }
                } else {
                    // Not a compound word
                    $this->highlightIntervals->skipInterval();
                }
            }
        }

        return $this->highlightIntervals;
    }

    /**
     * @param non-empty-string $pattern
     *
     * @return list<array{0: string, 1: int}>
     */
    private function splitWithOffsets(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject, -1, \PREG_SPLIT_OFFSET_CAPTURE);
        if ($parts === false) {
            return [];
        }

        return array_map($this->normalizeSplitPart(...), $parts);
    }

    /** @return array{0: string, 1: int} */
    private function normalizeSplitPart(mixed $part): array
    {
        if (
            !\is_array($part)
            || !isset($part[0], $part[1])
            || !\is_string($part[0])
            || !\is_int($part[1])
        ) {
            throw new RuntimeException('Invalid result returned by preg_split().');
        }

        return [$part[0], $part[1]];
    }

    /**
     * @param array<int|string, int> $flippedStems
     */
    private function findMatchingStem(string $word, array $flippedStems): ?string
    {
        if (isset($flippedStems[$word])) {
            return $word;
        }

        foreach (StemmerHelper::stemWords($this->stemmer, $word) as $stem) {
            if (isset($flippedStems[$stem])) {
                return $stem;
            }
        }

        return null;
    }

    protected function getLineWithoutMaskedFragments(): string
    {
        if ($this->lineWithoutMaskedFragments !== null) {
            return $this->lineWithoutMaskedFragments;
        }

        $this->lineWithoutMaskedFragments = SnippetTextHelper::sanitize($this->line, $this->maskRegexArray, $this->maskedFragments);

        return $this->lineWithoutMaskedFragments;
    }

    protected function restoreMaskedFragments(string $line): string
    {
        return SnippetTextHelper::restore($line, $this->maskedFragments);
    }
}
