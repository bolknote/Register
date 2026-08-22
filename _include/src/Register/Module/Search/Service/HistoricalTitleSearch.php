<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Rose\Entity\TocEntryWithMetadata;
use Register\Rose\Storage\Database\PdoStorage;

/** Matches quick-search titles after historical spelling has been canonicalized. */
final readonly class HistoricalTitleSearch
{
    /** Characters which may be produced, replaced or removed by historical normalization. */
    private const string UNSAFE_ANCHOR_CHARACTERS = 'аеёиоуяюзфксптыъ';

    public function __construct(
        private PdoStorage                  $storage,
        private HistoricalRussianNormalizer $normalizer,
    ) {
    }

    /** @return list<TocEntryWithMetadata> */
    public function find(string $query): array
    {
        $needle = $this->comparisonKey($query);
        $result = [];

        foreach ($this->candidates($needle) as $candidate) {
            if (
                $needle === ''
                || str_contains($this->comparisonKey($candidate->getTocEntry()->getTitle()), $needle)
            ) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /** Returns an HTML-safe title with the literal match, or its complete historical word, highlighted. */
    public function highlight(string $title, string $query): string
    {
        if ($query === '') {
            return register_htmlencode($title);
        }

        $literalPosition = mb_stripos($title, $query);
        if ($literalPosition !== false) {
            return $this->highlightCharacterRange($title, $literalPosition, mb_strlen($query));
        }

        $needle = $this->comparisonKey($query);
        if ($needle === '') {
            return register_htmlencode($title);
        }

        [$normalizedTitle, $segments] = $this->normalizeWithSegments($title);
        $normalizedPosition           = mb_strpos($normalizedTitle, $needle);
        if ($normalizedPosition === false) {
            return register_htmlencode($title);
        }

        $normalizedEnd = $normalizedPosition + mb_strlen($needle);
        $firstRawByte   = null;
        $lastRawByte    = null;
        foreach ($segments as $segment) {
            if (
                !$segment['word']
                || $segment['normalizedEnd'] <= $normalizedPosition
                || $segment['normalizedStart'] >= $normalizedEnd
            ) {
                continue;
            }

            $firstRawByte ??= $segment['rawStart'];
            $lastRawByte = $segment['rawEnd'];
        }

        if ($firstRawByte === null || $lastRawByte === null) {
            return register_htmlencode($title);
        }

        return register_htmlencode(substr($title, 0, $firstRawByte))
            . '<em>' . register_htmlencode(substr($title, $firstRawByte, $lastRawByte - $firstRawByte)) . '</em>'
            . register_htmlencode(substr($title, $lastRawByte));
    }

    /** @return list<TocEntryWithMetadata> */
    private function candidates(string $normalizedQuery): array
    {
        $anchor = $this->safeAnchor($normalizedQuery);
        if ($anchor === null) {
            return array_values($this->storage->getTocByTitlePrefix(''));
        }

        $result = [];
        foreach (array_unique([$anchor, mb_strtoupper($anchor), mb_strtolower($anchor)]) as $variant) {
            foreach ($this->storage->getTocByTitlePrefix($variant) as $entry) {
                $result[$entry->getExternalId()->toString()] = $entry;
            }
        }

        return array_values($result);
    }

    private function safeAnchor(string $normalizedQuery): ?string
    {
        $characters = preg_split('//u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($characters)) {
            return null;
        }

        foreach ($characters as $character) {
            if (
                preg_match('/^[\p{L}\p{N}]$/u', $character) === 1
                && !str_contains(self::UNSAFE_ANCHOR_CHARACTERS, $character)
            ) {
                return $character;
            }
        }

        return null;
    }

    private function comparisonKey(string $text): string
    {
        return strtr($this->normalizer->normalizeText($text), ['ё' => 'е']);
    }

    private function highlightCharacterRange(string $title, int $start, int $length): string
    {
        return register_htmlencode(mb_substr($title, 0, $start))
            . '<em>' . register_htmlencode(mb_substr($title, $start, $length)) . '</em>'
            . register_htmlencode(mb_substr($title, $start + $length));
    }

    /**
     * @return array{
     *     string,
     *     list<array{normalizedStart: int, normalizedEnd: int, rawStart: int, rawEnd: int, word: bool}>
     * }
     */
    private function normalizeWithSegments(string $title): array
    {
        $rawSegments = preg_split(
            '/([\p{L}\p{M}]+)/u',
            $title,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );
        if (!\is_array($rawSegments)) {
            return [$this->comparisonKey($title), []];
        }

        $normalizedTitle  = '';
        $normalizedOffset = 0;
        $rawOffset        = 0;
        $segments         = [];
        foreach ($rawSegments as $rawSegment) {
            $word              = preg_match('/^[\p{L}\p{M}]+$/u', $rawSegment) === 1;
            $normalizedSegment = $word ? $this->comparisonKey($rawSegment) : $rawSegment;
            $normalizedLength  = mb_strlen($normalizedSegment);
            $rawLength         = \strlen($rawSegment);

            $segments[] = [
                'normalizedStart' => $normalizedOffset,
                'normalizedEnd'   => $normalizedOffset + $normalizedLength,
                'rawStart'        => $rawOffset,
                'rawEnd'          => $rawOffset + $rawLength,
                'word'            => $word,
            ];

            $normalizedTitle .= $normalizedSegment;
            $normalizedOffset += $normalizedLength;
            $rawOffset += $rawLength;
        }

        return [$normalizedTitle, $segments];
    }
}
