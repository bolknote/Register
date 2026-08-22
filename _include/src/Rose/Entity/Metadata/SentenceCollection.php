<?php

declare(strict_types = 1);

/**
 * @copyright 2023-2024 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity\Metadata;

use Register\Rose\Helper\StringHelper;

class SentenceCollection
{
    /** @var list<string> */
    private array $sentences = [];

    /** @var list<string>|null */
    private ?array $cachedWords = null;

    /** @var array<int, SnippetSource>|null */
    private ?array $cachedSnippetSources = null;

    public function __construct(private readonly int $formatId)
    {
    }

    /**
     * @param string $text Text content of a sentence. Must be formatted according to the constructor parameter.
     */
    public function attach(string $text): void
    {
        $this->cachedWords          = null;
        $this->cachedSnippetSources = null;
        $this->sentences[]          = trim(preg_replace('#\\s+#', ' ', $text) ?? $text);
    }

    public function getText(): string
    {
        return implode(' ', $this->sentences);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->sentences;
    }

    /**
     * @return string[]
     */
    public function getWordsArray(): array
    {
        if ($this->cachedWords === null) {
            $this->buildWordsInfo();
        }

        return $this->cachedWords ?? [];
    }

    /**
     * @return array<int, SnippetSource>
     */
    public function getSnippetSources(): array
    {
        if ($this->cachedSnippetSources === null) {
            $this->buildWordsInfo();
        }

        return $this->cachedSnippetSources ?? [];
    }

    private function buildWordsInfo(): void
    {
        $wordsBySentence = [];
        $snippetSources  = [];
        $oldSize         = 0;
        foreach ($this->sentences as $idx => $sentence) {
            // NOTE: maybe it's worth to join sentences somehow before exploding for optimization reasons
            $contentWords        = self::breakIntoWords(
                $this->formatId === SnippetSource::FORMAT_INTERNAL ? StringHelper::clearInternalFormatting($sentence) : $sentence
            );
            $wordsBySentence[]   = $contentWords;
            $wordsInSentence     = \count($contentWords);
            if ($wordsInSentence === 0) {
                continue;
            }

            $newSize = $wordsInSentence + $oldSize;

            if ($wordsInSentence >= 2) { // Skip too short snippets
                $snippetSources[$idx] = new SnippetSource($sentence, $this->formatId, $oldSize, $newSize - 1);
            }

            $oldSize = $newSize;
        }

        $this->cachedWords          = array_merge(...$wordsBySentence);
        $this->cachedSnippetSources = $snippetSources;
    }

    /**
     * @return list<string>
     */
    public static function breakIntoWords(string $content): array
    {
        // Replace decimal separator: ',' -> '.'
        $content = preg_replace('#(?:^|[\s()])-?\d+\K,(?=\d+(?:$|[\s()]|\.\s))#', '.', $content) ?? $content;

        // We allow letters, digits and some punctuation: ".,-^_"
        $content = str_replace(',', ', ', $content);
        $content = preg_replace('#[^\\-.,0-9\\p{L}^_]+#u', ' ', $content) ?? $content;
        $content = mb_strtolower($content);
        $content = str_replace(['ё'], ['е'], $content);

        // These punctuation characters are meant to be inside words and numbers.
        // Remove trailing characters when splitting the words.
        $content = rtrim($content, '-.,');

        $words = preg_split('#[\\-.,]*?[ ]+#S', $content);
        if ($words === false) {
            return [];
        }

        StringHelper::removeLongWords($words);

        return $words;
    }
}
