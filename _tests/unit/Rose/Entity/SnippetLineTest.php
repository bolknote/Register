<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2024 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test\Entity;

use Codeception\Test\Unit;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\SnippetLine;
use S2\Rose\Exception\RuntimeException;
use S2\Rose\Stemmer\PorterStemmerEnglish;

/**
 * @group snippet
 * @group snippet-line
 */
final class SnippetLineTest extends Unit
{
    public function testCreateHighlighted1(): void
    {
        $snippetLine = new SnippetLine(
            'Testing string to highlight some test values, Test is case-sensitive.',
            SnippetSource::FORMAT_PLAIN_TEXT,
            new PorterStemmerEnglish(),
            ['test', 'is'],
            2
        );

        self::assertSame(
            '<i>Testing</i> string to highlight some <i>test</i> values, <i>Test is</i> case-sensitive.',
            $snippetLine->getHighlighted('<i>%s</i>', false)
        );
    }

    public function testCreateHighlighted2(): void
    {
        $snippetLine = new SnippetLine(
            'Testing string to highlight some test values, Test is case-sensitive.',
            SnippetSource::FORMAT_PLAIN_TEXT,
            new PorterStemmerEnglish(),
            ['Test'], // unknown stem, stems are normalized to lower case, however there is a match due to direct comparison
            1
        );

        self::assertSame(
            'Testing string to highlight some test values, <i>Test</i> is case-sensitive.',
            $snippetLine->getHighlighted('<i>%s</i>', false)
        );
    }

    public function testJoinHighlighted(): void
    {
        $snippetLine = new SnippetLine(
            'Testing string to highlight some test values, Test is case-sensitive.',
            SnippetSource::FORMAT_PLAIN_TEXT,
            new PorterStemmerEnglish(),
            ['to', 'highlight'],
            1
        );

        self::assertSame(
            'Testing string <i>to highlight</i> some test values, Test is case-sensitive.',
            $snippetLine->getHighlighted('<i>%s</i>', false)
        );
    }

    public function testCreateHighlightedFail(): void
    {
        $snippetLine = new SnippetLine(
            'Testing string to highlight some test values, Test is case-sensitive.',
            SnippetSource::FORMAT_PLAIN_TEXT,
            new PorterStemmerEnglish(),
            ['test', 'is'],
            2
        );
        $this->expectException(RuntimeException::class);
        $snippetLine->getHighlighted('<i></i>', false);
    }
}
