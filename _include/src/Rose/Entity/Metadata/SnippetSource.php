<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity\Metadata;

use Register\Rose\Exception\InvalidArgumentException;

class SnippetSource implements \Stringable
{
    /**
     * Snippets in search results can store formatting information.
     * There are now 2 formatting options available: no formatting and so-called "internal" formatting.
     *
     * In the first case (FORMAT_PLAIN_TEXT), the text is stored in the snippet as it is.
     *
     * In the second case (FORMAT_INTERNAL), the backslash character starts to play a special role.
     * In internal formatting, backslashes in the source text must be escaped, i.e. \ is changed to \\.
     * Then, a single slash and the following character encode a formatting alternation.
     * For example, in the sentence "This is a \bbold\B example", the word "bold" is bolded.
     * Similarly, \i and \I indicate italics, \u and \U indicate superscripts, and \d and \D indicate subscripts.
     *
     * The formatting is supposed to be correct, properly balanced. When converting to html,
     * formatting characters are translated into html tags by usual substitution of substrings.
     * Incorrect internal formatting will lead to incorrect html in the output.
     *
     * @see \Register\Rose\Helper\StringHelper::convertInternalFormattingToHtml for details of internal formatting processing.
     */
    public const int FORMAT_PLAIN_TEXT = 0;

    public const int FORMAT_INTERNAL   = 1;

    private const array ALLOWED_FORMATS   = [self::FORMAT_PLAIN_TEXT, self::FORMAT_INTERNAL];

    private readonly int $minPosition;

    private readonly int $maxPosition;

    private readonly int $formatId;

    public function __construct(private readonly string $text, int $formatId, int $minPosition, int $maxPosition)
    {
        if ($minPosition < 0) {
            throw new InvalidArgumentException('Word position cannot be negative.');
        }

        if ($minPosition > $maxPosition) {
            throw new InvalidArgumentException('Minimal word position cannot be greater than maximal.');
        }

        if (!\in_array($formatId, self::ALLOWED_FORMATS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown snippet format "%s".', $formatId));
        }

        $this->minPosition = $minPosition;
        $this->maxPosition = $maxPosition;
        $this->formatId    = $formatId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getMinPosition(): int
    {
        return $this->minPosition;
    }

    public function getMaxPosition(): int
    {
        return $this->maxPosition;
    }

    public function getFormatId(): int
    {
        return $this->formatId;
    }

    /**
     * @param int[] $positions
     */
    public function coversOneOfPositions(array $positions): bool
    {
        foreach ($positions as $position) {
            if ($position >= $this->minPosition && $position <= $this->maxPosition) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->text;
    }
}
