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
        $normalizer = new HybridWordNormalizer($this->dictionary(), $fallback);
        $unknown    = 'шмякозаврами';

        self::assertSame(['человек'], $normalizer->normalizeWord('люди'));
        self::assertSame($fallback->stemWord('люди'), $normalizer->stemWord('люди'));
        self::assertSame([$fallback->stemWord($unknown)], $normalizer->normalizeWord($unknown));
        self::assertSame([$fallback->stemWord('Searching')], $normalizer->normalizeWord('Searching'));
    }

    public function testDictionaryLemmasWorkThroughRoseIndexSearchAndHighlighting(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'morphology_test_');
        $storage->erase();

        $normalizer = new HybridWordNormalizer(
            $this->dictionary(),
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

    public function testTagSimilarityUsesEveryAmbiguousNormalForm(): void
    {
        $normalizer = new HybridWordNormalizer(
            $this->dictionary(),
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

    private function dictionary(): OpenCorporaDictionary
    {
        return self::$dictionary ??= new OpenCorporaDictionary(
            dirname(__DIR__, 6) . '/_include/src/Register/Module/Search/resources/morphology/ru',
        );
    }
}
