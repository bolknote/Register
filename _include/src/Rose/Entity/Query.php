<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 */

namespace S2\Rose\Entity;

use S2\Rose\Helper\StringHelper;

/**
 * @see \S2\Rose\Test\Entity\QueryTest
 */
class Query
{
    private const int MAX_WORDS = 64;

    protected ?int $instanceId = null;

    protected ?int $limit = null;

    protected int $offset = 0;

    public function __construct(protected mixed $value)
    {
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getInstanceId(): ?int
    {
        return $this->instanceId;
    }

    public function setInstanceId(?int $instanceId): static
    {
        $this->instanceId = $instanceId;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function valueToArray(): array
    {
        $content = $this->normalizeValue($this->value);
        if ($content === '') {
            return [];
        }

        $content = strip_tags($content);

        // Normalize
        $content = str_replace(['«', '»', '“', '”', '‘', '’'], '"', $content);
        $content = str_replace('−', '-', $content); // Replace minus sign to a hyphen
        $content = str_replace(['---', '–', '−'], '—', $content); // Normalize dashes
        $content = $this->safePregReplace('#,\\s+,#u', ',,', $content);
        $content = $this->safePregReplace('#[^\\-\\p{L}0-9^_.,()";?!…:—]+#iu', ' ', $content);
        $content = mb_strtolower($content);

        // Replace decimal separators: ',' -> '.'
        $content = $this->safePregReplace('#(?<=^|\\s)(\\-?\\d+),(\\d+)(?=\\s|$)#u', '\\1.\\2', $content);

        // Separate special chars at the beginning of the word
        $count = 0;
        while (true) {
            $content = $this->safePregReplace('#(?:^|\\s)\K([—^()"?:!])(?=[^\s])#u', '\\1 ', $content, -1, $count);
            if ($count === 0 || $content === '') {
                break;
            }
        }

        // Separate special chars at the end of the word
        while (true) {
            $content = $this->safePregReplace('#(?<=[^\s])([—^()"?:!])(?=\\s|$)#u', ' \\1', $content, -1, $count);
            if ($count === 0 || $content === '') {
                break;
            }
        }

        // Separate groups of commas
        $content = $this->safePregReplace('#(,+)#u', ' \\1 ', $content);

        $words = preg_split('#\\s+#', $content);
        if ($words === false) {
            return [];
        }

        foreach ($words as $k => &$v) {
            // Replace 'ё' inside words
            if ($v !== 'ё' && str_contains($v, 'ё')) {
                $v = str_replace('ё', 'е', $v);
            }

            if ($v === '' || preg_match('#[\\p{L}\\d]#u', $v) !== 1) {
                continue;
            }

            $trimmed = rtrim($v, StringHelper::WORD_COMPONENT_DELIMITERS);
            if ($trimmed === '') {
                unset($words[$k]);
                continue;
            }

            $v = $trimmed;
        }

        unset($v);

        $words = array_values(array_unique($words));

        StringHelper::removeLongWords($words);

        // Fix keys
        // $words = array_values($words); // <- moved to helper

        if (\count($words) > self::MAX_WORDS) {
            return \array_slice($words, 0, self::MAX_WORDS);
        }

        return $words;
    }

    private function normalizeValue(mixed $value): string
    {
        if (\is_string($value)) {
            $stringValue = $value;
        } elseif (\is_scalar($value) || $value instanceof \Stringable) {
            $stringValue = (string)$value;
        } else {
            return '';
        }

        return $this->normalizeUtf8($stringValue);
    }

    private function normalizeUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $previousSubstituteCharacter = mb_substitute_character();
        if (!$this->setSubstituteCharacter('none')) {
            return '';
        }

        try {
            return $this->normalizeEncodingResult(mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
        } finally {
            if (!$this->setSubstituteCharacter($previousSubstituteCharacter)) {
                throw new \RuntimeException('Unable to restore the mbstring substitution character.');
            }
        }
    }

    /** @param int|'entity'|'long'|'none' $value */
    private function setSubstituteCharacter(string|int $value): bool
    {
        return mb_substitute_character($value);
    }

    private function normalizeEncodingResult(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @param non-empty-string    $pattern
     * @param int<0, max>|null    $count
     * @param-out int<0, max>     $count
     */
    private function safePregReplace(string $pattern, string $replacement, string $subject, int $limit = -1, ?int &$count = null): string
    {
        $result = preg_replace($pattern, $replacement, $subject, $limit, $count);
        if ($result === null) {
            $count = 0;

            return '';
        }

        $count = $this->normalizeReplacementCount($count);

        return $result;
    }

    private function normalizeReplacementCount(mixed $count): int
    {
        return \is_int($count) && $count >= 0 ? $count : 0;
    }
}
