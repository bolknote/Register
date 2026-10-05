<?php

declare(strict_types = 1);

namespace unit\Register\Module\Search\Service;

use Codeception\Test\Unit;
use Register\Module\Search\Service\SimilarWordsDetector;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;

final class SimilarWordsDetectorTest extends Unit
{
    /**
     * @param array<mixed> $queryWords
     * @dataProvider matchingWordsProvider
     */
    public function testMatchesExactShortTokensWithoutMakingThemPrefixes(string $tag, array $queryWords, bool $expected): void
    {
        $detector = new SimilarWordsDetector(new PorterStemmerRussian(new PorterStemmerEnglish()));

        self::assertSame($expected, $detector->wordIsSimilarToOtherWords($tag, $queryWords));
        self::assertSame($expected, $detector->wordIsSimilarToOtherWords($tag, $queryWords), 'Cached forms must have the same matching behavior.');
    }

    /** @return iterable<string, array{string, array<mixed>, bool}> */
    public static function matchingWordsProvider(): iterable
    {
        yield 'two-digit tag' => ['42', ['42'], true];
        yield 'one-digit tag' => ['7', ['7'], true];
        yield 'two-letter Latin tag' => ['Go', ['go'], true];
        yield 'two-letter Cyrillic tag' => ['ИИ', ['ии'], true];
        yield 'one-letter tag' => ['C', ['c'], true];
        yield 'short token in a compound tag' => ['Серия 42', ['42'], true];
        yield 'punctuation separates exact tokens' => ['Go / Rust', ['GO'], true];
        yield 'numeric prefix is not enough' => ['420', ['42'], false];
        yield 'numeric suffix is not enough' => ['142', ['42'], false];
        yield 'short letter prefix is not enough' => ['Google', ['go'], false];
        yield 'short letter suffix is not enough' => ['cargo', ['go'], false];
        yield 'different short tokens do not match' => ['C', ['go'], false];
        yield 'existing Russian stemming' => ['словами', ['слово'], true];
        yield 'existing long prefix matching' => ['электроника', ['электрон'], true];
        yield 'non-string query entries are ignored' => ['42', [42, null, false], false];
        yield 'empty tag does not match' => ['', ['42'], false];
        yield 'empty query does not match' => ['42', [], false];
        yield 'punctuation alone does not match' => ['+', ['+'], false];
    }
}
