<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultItem;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\File\SingleFileArrayStorage;

final class PhraseSearchTest extends Unit
{
    /** @dataProvider quotesProvider */
    public function testQuotedPhrasesRequireAnEntireOrderedSequence(string $query): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Пример', 'Красный цветок растёт.'),
            new Indexable('gap', 'Пример', 'Красный красивый цветок растёт.'),
            new Indexable('reverse', 'Пример', 'Цветок красный растёт.'),
            new Indexable('partial', 'Красный', 'Другая тема.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query($query))->getItems()));
    }

    /** @return \Iterator<string, array{string}> */
    public static function quotesProvider(): \Iterator
    {
        yield 'ASCII' => ['"красный цветок"'];
        yield 'guillemets' => ['«красный цветок»'];
        yield 'curly double quotes' => ['“красный цветок”'];
        yield 'low double quotes' => ['„красный цветок“'];
        yield 'curly single quotes' => ['‘красный цветок’'];
    }

    public function testRepeatedWordsNeedDifferentConsecutiveOccurrences(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Пример', 'Да да да.'),
            new Indexable('single', 'Пример', 'Да.'),
            new Indexable('gap', 'Пример', 'Да потом да.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('"да да"'))->getItems()));
    }

    public function testMatchingPairsElsewhereDoNotMakeAWholePhrase(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Example', 'red green blue'),
            new Indexable('pairs', 'Example', 'Red green flag. Green blue flag. Red bright blue flag.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('"red green blue"'))->getItems()));
    }

    public function testMorphologyWorksInsideQuotes(): void
    {
        $finder = $this->finder([
            new Indexable('literal', 'Пример', 'Дети играют.'),
            new Indexable('inflected', 'Ребёнок играл', 'Описание игры.'),
            new Indexable('gap', 'Ребёнок весело играл', 'Описание игры.'),
        ]);

        self::assertSame(['literal', 'inflected'], $this->ids($finder->find(new Query('"дети играют"'))->getItems()));
    }

    public function testEnglishApostrophesInsideCurlyQuotesDoNotEndThePhrase(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Example', "Reader's children's books."),
            new Indexable('partial', 'Example', "Reader's books."),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('‘reader’s children’s books’'))->getItems()));
    }

    public function testMatchesCannotBeAssembledFromSeparateFields(): void
    {
        $finder = $this->finder([
            new Indexable('title', 'Красный цветок', 'Другая тема.'),
            (new Indexable('keywords', 'Пример', 'Другая тема.'))->setKeywords('красный цветок'),
            new Indexable('split', 'Красный', 'Один цветок растёт.'),
        ]);

        self::assertEqualsCanonicalizing(['title', 'keywords'], $this->ids($finder->find(new Query('"красный цветок"'))->getItems()));
    }

    public function testACompoundWordCannotReuseItsPositionForTwoPhraseWords(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Example', 'A well known fact.'),
            new Indexable('compound', 'Example', 'A well-known fact.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('"well known"'))->getItems()));
        self::assertSame(['compound'], $this->ids($finder->find(new Query('"well-known"'))->getItems()));
    }

    public function testConnectorsInsidePhrasesRemainRequired(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Чай с молоком', 'Горячий напиток.'),
            new Indexable('other', 'Чай без молока', 'Горячий напиток.'),
            new Indexable('missing', 'Чай молоко', 'Горячий напиток.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('"чай с молоком"'))->getItems()));
    }

    public function testAllQuotedPhrasesAreRequiredAndMayMatchDifferentFields(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Красный цветок', 'Белый дом стоит рядом.'),
            new Indexable('first', 'Красный цветок', 'Другой предмет.'),
            new Indexable('second', 'Белый дом', 'Другой предмет.'),
        ]);

        self::assertSame(['match'], $this->ids($finder->find(new Query('"красный цветок" «белый дом»'))->getItems()));
    }

    public function testUnquotedWordsBoostResultsWithoutBecomingMandatory(): void
    {
        $finder = $this->finder([
            new Indexable('boosted', 'Архив', 'Красный цветок нарисован на листе.'),
            new Indexable('phrase', 'Рисунок', 'Красный цветок нарисован на листе.'),
            new Indexable('no-phrase', 'Архив', 'Красный предмет и цветок нарисованы на листе.'),
        ]);

        self::assertSame(['boosted', 'phrase'], $this->ids($finder->find(new Query('архив "красный цветок"'))->getItems()));
    }

    public function testFilteringRunsBeforeCountingAndPagination(): void
    {
        $finder = $this->finder([
            new Indexable('first', 'Example', 'red blue red blue'),
            new Indexable('second', 'Example', 'red blue'),
            new Indexable('invalid', 'red bright blue', 'Another subject.'),
        ]);

        $firstPage = $finder->find((new Query('"red blue"'))->setLimit(1));
        self::assertSame(2, $firstPage->getTotalCount());
        self::assertSame(['first'], $this->ids($firstPage->getItems()));
        self::assertSame(['second'], $this->ids($finder->find((new Query('"red blue"'))->setLimit(1)->setOffset(1))->getItems()));
        self::assertSame([], $finder->find(new Query('"red purple blue"'))->getItems());
    }

    public function testInlineMarkupAndPunctuationDoNotBreakTheWordSequence(): void
    {
        $finder = $this->finder([
            new Indexable('match', 'Пример', '<p><strong>Красный</strong>, <em>цветок</em> растёт.</p>'),
        ]);
        $finder->setHighlightTemplate('<mark>%s</mark>');

        $items = $finder->find(new Query('"Красный, цветок"'))->getItems();

        self::assertCount(1, $items);
        self::assertStringContainsString('<mark>', $items[0]->getSnippet());
        self::assertStringContainsString('Красный', $items[0]->getSnippet());
        self::assertStringContainsString('цветок', $items[0]->getSnippet());
    }

    public function testARequiredPhraseIsNotSilentlyTruncatedAtTheQueryWordLimit(): void
    {
        $words = array_map(static fn(int $i): string => 'word' . $i, range(0, 64));
        $finder = $this->finder([new Indexable('long', 'Example', implode(' ', $words))]);

        self::assertSame([], $this->ids($finder->find(new Query('"' . implode(' ', $words) . '"'))->getItems()));
    }

    public function testFileStorageStopWordsLeavePositionalGaps(): void
    {
        $storage = new SingleFileArrayStorage(__DIR__ . '/../../../../tmp/phrase-search.php');
        $normalizer = new PorterStemmerEnglish();
        $indexer = new Indexer($storage, $normalizer);
        for ($i = 0; $i < 21; ++$i) {
            $indexer->index(new Indexable('filler-' . $i, 'Example', 'and'));
        }

        $indexer->index(new Indexable('match', 'Example', 'tea and milk'));
        $indexer->index(new Indexable('gap', 'Example', 'tea milk'));

        $storage->cleanup();

        self::assertTrue($storage->isExcludedWord('and'));
        self::assertSame(['match'], $this->ids((new Finder($storage, $normalizer))->find(new Query('"tea and milk"'))->getItems()));
    }

    /** @param list<Indexable> $documents */
    private function finder(array $documents): Finder
    {
        $normalizer = new HybridWordNormalizer(
            new HistoricalRussianNormalizer(
                new ChurchSlavonicNormalizer(),
                new PreReformRussianNormalizer(),
                new OpenCorporaDictionary(dirname(__DIR__, 5) . '/_include/src/Register/Module/Search/resources/morphology/ru'),
            ),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'phrase_test_');
        $storage->erase();

        $indexer = new Indexer($storage, $normalizer);
        foreach ($documents as $document) {
            $indexer->index($document);
        }

        return new Finder($storage, $normalizer);
    }

    /**
     * @param list<ResultItem> $items
     * @return list<string>
     */
    private function ids(array $items): array
    {
        return array_map(static fn(ResultItem $item): string => $item->getId(), $items);
    }
}
