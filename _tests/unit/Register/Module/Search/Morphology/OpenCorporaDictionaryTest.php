<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Morphology;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Module\Search\Service\SimilarWordsDetector;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultItem;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\PdoStorage;

final class OpenCorporaDictionaryTest extends Unit
{
    private static ?OpenCorporaDictionary $dictionary = null;

    /**
     * @dataProvider knownWordProvider
     * @param list<string> $normalForms
     */
    public function testReturnsNormalFormsForKnownWords(string $word, array $normalForms): void
    {
        self::assertSame($normalForms, $this->dictionary()->normalForms($word));
    }

    public function testReturnsEveryNormalFormForAnAmbiguousWord(): void
    {
        self::assertSame(['стать', 'сталь'], $this->dictionary()->normalForms('стали'));
    }

    public function testReturnsNothingForAnUnknownWord(): void
    {
        self::assertSame([], $this->dictionary()->normalForms('шмякозаврами'));
    }

    public function testHybridNormalizerFallsBackToPorter(): void
    {
        $fallback   = new PorterStemmerRussian(new PorterStemmerEnglish());
        $normalizer = new HybridWordNormalizer(
            $this->historicalNormalizer(),
            $fallback,
        );
        $unknown    = 'шмякозаврами';

        self::assertSame(['человек'], $normalizer->normalizeWord('люди'));
        self::assertSame($fallback->stemWord('люди'), $normalizer->stemWord('люди'));
        self::assertSame([$fallback->stemWord($unknown)], $normalizer->normalizeWord($unknown));
        self::assertSame([$fallback->stemWord('Searching')], $normalizer->normalizeWord('Searching'));
    }

    /** @dataProvider preReformSpellingProvider */
    public function testHybridNormalizerMatchesModernAndPreReformSpellings(string $oldSpelling, string $modernSpelling): void
    {
        $normalizer = $this->normalizer();

        self::assertSame(
            $normalizer->normalizeWord($modernSpelling),
            $normalizer->normalizeWord($oldSpelling),
        );
    }

    public function testModernWordEndingIsNotMistakenForPreReformGrammar(): void
    {
        self::assertSame(['чикаго'], $this->normalizer()->normalizeWord('Чикаго'));
    }

    public function testDictionaryLemmasWorkThroughRoseIndexSearchAndHighlighting(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'morphology_test_');
        $storage->erase();

        $normalizer = new HybridWordNormalizer(
            $this->historicalNormalizer(),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $indexer = new Indexer($storage, $normalizer);
        $indexer->index(new Indexable('people', 'История', 'Люди пришли. Дети играли. Они стали сильнее.'));
        $indexer->index(new Indexable('metal', 'Материал', 'Прочная сталь выдержала нагрузку.'));

        $finder = new Finder($storage, $normalizer);
        $finder->setHighlightTemplate('<b>%s</b>');

        $people = $finder->find(new Query('человек'))->getItems();
        self::assertSame(['people'], array_map(static fn(ResultItem $item): string => $item->getId(), $people));
        self::assertSame('<b>Люди</b> пришли.', $people[0]->getSnippet());

        $children = $finder->find(new Query('ребенок'))->getItems();
        self::assertSame(['people'], array_map(static fn(ResultItem $item): string => $item->getId(), $children));
        self::assertSame('<b>Дети</b> играли.', $children[0]->getSnippet());

        $verb = $finder->find(new Query('стать'))->getItems();
        self::assertSame(['people'], array_map(static fn(ResultItem $item): string => $item->getId(), $verb));
        self::assertSame('Они <b>стали</b> сильнее.', $verb[0]->getSnippet());

        $noun = $finder->find(new Query('сталь'))->getItems();
        self::assertEqualsCanonicalizing(
            ['people', 'metal'],
            array_map(static fn(ResultItem $item): string => $item->getId(), $noun),
        );
    }

    public function testExactInflectionOutranksStrongerLemmaOnlyMatches(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'exact_form_test_');
        $storage->erase();

        $normalizer = $this->normalizer();
        $indexer    = new Indexer($storage, $normalizer);
        $indexer->index(new Indexable(
            'lemma-title',
            'Старостин Фёдор Николаевич',
            'Старостина вспоминали. О Старостиных написано ещё много.',
        ));
        $indexer->index(new Indexable(
            'exact',
            'Бабушкино свидетельство о рождении',
            'Свидетельство о рождении Клавдии Фёдоровны Старостиной.',
        ));

        $items = (new Finder($storage, $normalizer))->find(new Query('старостиной'))->getItems();

        self::assertSame(
            ['exact', 'lemma-title'],
            array_map(static fn(ResultItem $item): string => $item->getId(), $items),
        );
    }

    public function testPreReformSpellingsWorkThroughRoseInBothDirections(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'pre_reform_test_');
        $storage->erase();

        $normalizer = $this->normalizer();
        $indexer    = new Indexer($storage, $normalizer);
        $indexer->index(new Indexable('old', 'Старая орѳографія', 'Дѣти читали про мiръ и новыя книги. Ксенія читала ѱаломъ.'));
        $indexer->index(new Indexable('modern', 'Современный текст', 'Фома посетил синод. Отрок учил чадо. Это страница хорошего журнала.'));

        $finder = new Finder($storage, $normalizer);
        $finder->setHighlightTemplate('<b>%s</b>');

        foreach (['дети', 'мир', 'новые', 'ксения', 'псалом'] as $modernQuery) {
            self::assertSame(
                ['old'],
                array_map(
                    static fn(ResultItem $item): string => $item->getId(),
                    $finder->find(new Query($modernQuery))->getItems(),
                ),
                $modernQuery,
            );
        }

        foreach (['Ѳома', 'сѵнодъ', 'ѿрокъ', 'чѧдо', 'хорошаго'] as $oldQuery) {
            self::assertSame(
                ['modern'],
                array_map(
                    static fn(ResultItem $item): string => $item->getId(),
                    $finder->find(new Query($oldQuery))->getItems(),
                ),
                $oldQuery,
            );
        }

        $children = $finder->find(new Query('дети'))->getItems();
        self::assertSame('<b>Дѣти</b> читали про мiръ и новыя книги.', $children[0]->getSnippet());

        $foma = $finder->find(new Query('Ѳома'))->getItems();
        self::assertSame('<b>Фома</b> посетил синод.', $foma[0]->getSnippet());
    }

    public function testTagSimilarityUsesEveryAmbiguousNormalForm(): void
    {
        $normalizer = new HybridWordNormalizer(
            $this->historicalNormalizer(),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $detector = new SimilarWordsDetector($normalizer);

        self::assertTrue($detector->wordIsSimilarToOtherWords('стали', ['стать']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('стали', ['сталь']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('міръ', ['мир']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('мир', ['міръ']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('Ѯенія', ['ксения']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('ксения', ['Ѯенія']));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function knownWordProvider(): iterable
    {
        yield 'regular inflection' => ['кошки', ['кошка']];
        yield 'suppletive plural' => ['люди', ['человек']];
        yield 'suppletive children' => ['дети', ['ребёнок']];
        yield 'irregular verb' => ['будешь', ['быть']];
        yield 'е spelling finds ё' => ['елки', ['ёлка']];
        yield 'ё spelling stays supported' => ['ёлки', ['ёлка']];
    }

    /** @return iterable<string, array{string, string}> */
    public static function preReformSpellingProvider(): iterable
    {
        yield 'yat' => ['дѣти', 'дети'];
        yield 'decimal i' => ['Россія', 'Россия'];
        yield 'fita' => ['Ѳома', 'Фома'];
        yield 'izhitsa and final hard sign' => ['сѵнодъ', 'синод'];
        yield 'final hard sign' => ['міръ', 'мир'];
        yield 'hard adjective ending' => ['новаго', 'нового'];
        yield 'sibilant adjective ending' => ['хорошаго', 'хорошего'];
        yield 'soft adjective ending' => ['дальняго', 'дальнего'];
        yield 'feminine plural hard ending' => ['новыя', 'новые'];
        yield 'feminine plural soft ending' => ['хорошія', 'хорошие'];
        yield 'feminine pronoun' => ['онѣ', 'они'];
        yield 'feminine pronoun case form' => ['однѣхъ', 'одних'];
        yield 'OCR decimal i' => ['мiръ', 'мир'];
        yield 'OCR uppercase decimal i' => ['Iюнь', 'июнь'];
        yield 'ksi and decimal i' => ['ѯенія', 'ксения'];
        yield 'psi and final hard sign' => ['ѱаломъ', 'псалом'];
        yield 'ot ligature' => ['ѿрокъ', 'отрок'];
        yield 'little yus after sibilant' => ['чѧдо', 'чадо'];
        yield 'monograph uk' => ['ѹчитель', 'учитель'];
        yield 'yeru with back yer' => ['мꙑсль', 'мысль'];
        yield 'Church Slavonic accent' => ["сло\u{0301}во", 'слово'];
    }

    private function normalizer(): HybridWordNormalizer
    {
        return new HybridWordNormalizer(
            $this->historicalNormalizer(),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
    }

    private function historicalNormalizer(): HistoricalRussianNormalizer
    {
        return new HistoricalRussianNormalizer(
            new ChurchSlavonicNormalizer(),
            new PreReformRussianNormalizer(),
            $this->dictionary(),
        );
    }

    private function dictionary(): OpenCorporaDictionary
    {
        return self::$dictionary ??= new OpenCorporaDictionary(
            dirname(__DIR__, 6) . '/_include/src/Register/Module/Search/resources/morphology/ru',
        );
    }
}
