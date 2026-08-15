<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Morphology;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Module\Search\Service\SimilarWordsDetector;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\Query;
use S2\Rose\Entity\ResultItem;
use S2\Rose\Finder;
use S2\Rose\Indexer;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Stemmer\PorterStemmerRussian;
use S2\Rose\Storage\Database\PdoStorage;

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
            $this->dictionary(),
            new PreReformRussianNormalizer(),
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
            $this->dictionary(),
            new PreReformRussianNormalizer(),
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

    public function testPreReformSpellingsWorkThroughRoseInBothDirections(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'pre_reform_test_');
        $storage->erase();

        $normalizer = $this->normalizer();
        $indexer    = new Indexer($storage, $normalizer);
        $indexer->index(new Indexable('old', 'Старая орѳографія', 'Дѣти читали про міръ и новыя книги.'));
        $indexer->index(new Indexable('modern', 'Современный текст', 'Фома посетил синод. Это страница хорошего журнала.'));

        $finder = new Finder($storage, $normalizer);
        $finder->setHighlightTemplate('<b>%s</b>');

        foreach (['дети', 'мир', 'новые'] as $modernQuery) {
            self::assertSame(
                ['old'],
                array_map(
                    static fn(ResultItem $item): string => $item->getId(),
                    $finder->find(new Query($modernQuery))->getItems(),
                ),
                $modernQuery,
            );
        }

        foreach (['Ѳома', 'сѵнодъ', 'хорошаго'] as $oldQuery) {
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
        self::assertSame('<b>Дѣти</b> читали про міръ и новыя книги.', $children[0]->getSnippet());

        $foma = $finder->find(new Query('Ѳома'))->getItems();
        self::assertSame('<b>Фома</b> посетил синод.', $foma[0]->getSnippet());
    }

    public function testTagSimilarityUsesEveryAmbiguousNormalForm(): void
    {
        $normalizer = new HybridWordNormalizer(
            $this->dictionary(),
            new PreReformRussianNormalizer(),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $detector = new SimilarWordsDetector($normalizer);

        self::assertTrue($detector->wordIsSimilarToOtherWords('стали', ['стать']));
        self::assertTrue($detector->wordIsSimilarToOtherWords('стали', ['сталь']));
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
    }

    private function normalizer(): HybridWordNormalizer
    {
        return new HybridWordNormalizer(
            $this->dictionary(),
            new PreReformRussianNormalizer(),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
    }

    private function dictionary(): OpenCorporaDictionary
    {
        return self::$dictionary ??= new OpenCorporaDictionary(
            dirname(__DIR__, 6) . '/_include/src/Register/Module/Search/resources/morphology/ru',
        );
    }
}
