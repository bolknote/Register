<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity\Metadata;

use Register\Rose\Exception\LogicException;
use Register\Rose\Helper\StringHelper;

class SentenceMap
{
    public const string LINE_SEPARATOR = "\r";

    /**
     * [
     *    1 => [
     *       '/html/body/p[1]/text()' => 'One sentence.',
     *    ],
     *    2 => [
     *       '/html/body/p[2]/text()[1]' => 'Second',
     *       '/html/body/p[2]/br'        => ' ',
     *       '/html/body/p[2]/text()[2]' => 'sentence. And a third one.',
     *    ],
     * ]
     *
     * @var array<int, array<string, string>>
     */
    private array $paragraphs = [];

    /**
     * @param int $formatId Id of formatting.
     * @see SnippetSource::ALLOWED_FORMATS for formatting
     */
    public function __construct(private readonly int $formatId)
    {
    }

    /**
     * @param int    $paragraphIndex Number of current paragraph. Must be detected outside based on formatting.
     * @param string $path Some identifier of a content node. Must be unique for the paragraph given.
     * @param string $textContent Raw text content of a node. Formatting must correspond to formatId constructor parameter.
     */
    public function add(int $paragraphIndex, string $path, string $textContent): self
    {
        if (isset($this->paragraphs[$paragraphIndex][$path])) {
            throw new \LogicException(sprintf('Map already has a content for paragraph "%s" and path "%s".', $paragraphIndex, $path));
        }

        $this->paragraphs[$paragraphIndex][$path] = $textContent;

        return $this;
    }

    public function appendToLastItem(string $text): void
    {
        $a = $this->paragraphs;
        if (\count($a) === 0) {
            throw new LogicException('Cannot append to an empty sentence map.');
        }

        $lastKey = array_key_last($a);
        $a        = $a[$lastKey];
        $lastKey2 = array_key_last($a);
        if ($lastKey2 === null) {
            throw new LogicException('Cannot append to an empty paragraph.');
        }

        $this->paragraphs[$lastKey][$lastKey2] .= $text;
    }

    public function toSentenceCollection(): SentenceCollection
    {
        $sentenceCollection = new SentenceCollection($this->formatId);

        foreach ($this->paragraphs as $paragraphSentences) {
            $accumulatedRegularSentences = '';
            foreach ($paragraphSentences as $path => $paragraphSentence) {
                if (str_contains($path, '/pre') && str_contains($path, '/code')) {
                    // When a code block is encountered, do accumulated regular work
                    $this->processRegularSentences($accumulatedRegularSentences, $sentenceCollection);
                    $accumulatedRegularSentences = '';

                    // and process the code in a different way
                    $this->processCodeSentences($paragraphSentence, $sentenceCollection);
                } else {
                    // Merge non-code text content and then break into sentences.
                    $accumulatedRegularSentences .= $paragraphSentence;
                }
            }

            $this->processRegularSentences($accumulatedRegularSentences, $sentenceCollection);
        }

        return $sentenceCollection;
    }

    /**
     * Breaks a regular text into sentences using heuristics based on punctuation rules.
     */
    private function processRegularSentences(string $text, SentenceCollection $sentenceCollection): void
    {
        $text      = trim($text);
        $sentences = StringHelper::sentencesFromText($text, $this->formatId === SnippetSource::FORMAT_INTERNAL);

        $linesNum = 1 + substr_count($text, self::LINE_SEPARATOR);
        if ($linesNum > 3 && $sentences !== []) {
            $totalWordNum          = \count(SentenceCollection::breakIntoWords(
                $this->formatId === SnippetSource::FORMAT_INTERNAL ? StringHelper::clearInternalFormatting($text) : $text
            ));
            $avgWordNumInSentences = (float)$totalWordNum / (float)\count($sentences);
            $avgWordNumInLines     = (float)$totalWordNum / (float)$linesNum;

            if ($avgWordNumInSentences > 20 && $avgWordNumInLines > 3 && $avgWordNumInLines < 15) {
                // Heuristics for lines separated by <br>.
                // This branch is for lists like table of contents.
                $sentences = explode(self::LINE_SEPARATOR, $text);
            }
        }

        foreach ($sentences as $sentence) {
            if ($sentence === '') {
                continue;
            }

            $sentenceCollection->attach($sentence);
        }
    }

    /**
     * Breaks a source code into "sentences" using empty lines as a separator.
     */
    private function processCodeSentences(string $text, SentenceCollection $sentenceCollection): void
    {
        $sentences = StringHelper::sentencesFromCode($text);

        foreach ($sentences as $sentence) {
            if ($sentence === '') {
                continue;
            }

            $sentenceCollection->attach($sentence);
        }
    }
}
