<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Roman Parpalak
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

final class RankingTest extends Unit
{
    /**
     * @dataProvider coverageProvider
     * @param list<Indexable> $documents
     */
    public function testCompleteQueryOutranksAnExactPartialMatch(string $query, array $documents): void
    {
        $finder = $this->finder($documents);
        $result = $finder->find(new Query($query));

        self::assertSame(['complete', 'partial'], $this->ids($result->getItems()));
        self::assertSame(2, $result->getTotalCount());
        self::assertSame(['complete'], $this->ids($finder->find((new Query($query))->setLimit(1))->getItems()));
        self::assertSame(['partial'], $this->ids($finder->find((new Query($query))->setLimit(1)->setOffset(1))->getItems()));
    }

    /** @return \Iterator<string, array{string, list<Indexable>}> */
    public static function coverageProvider(): \Iterator
    {
        yield 'Russian inflections' => ['история театра', [
            new Indexable('complete', 'Истории театров', 'Развитие сценического искусства.'),
            new Indexable('partial', 'История велосипеда', 'Как появились велосипеды.'),
        ]];
        yield 'English inflections' => ['history theatre', [
            new Indexable('complete', 'Histories of theatres', 'The development of stage performances.'),
            new Indexable('partial', 'History of bicycles', 'The invention of bicycles.'),
        ]];
        yield 'ambiguous word counts once' => ['стали сильнее', [
            new Indexable('complete', 'Стать сильным', 'Упражнения для развития мышц.'),
            new Indexable('partial', 'Стали', 'Сталь стала материалом для каркаса.'),
        ]];
        yield 'matches across fields' => ['солнечная батарея', [
            new Indexable('complete', 'Батареи', 'Используют солнечный свет.'),
            new Indexable('partial', 'Солнечная погода', 'Прогноз на завтра.'),
        ]];
        yield 'Russian connectors' => ['о театрах и истории', [
            new Indexable('complete', 'История театра', 'Развитие сценического искусства.'),
            new Indexable('partial', 'О велосипедах и самокатах', 'Городской транспорт.'),
        ]];
        yield 'English connectors' => ['the history of theatres', [
            new Indexable('complete', 'Histories about theatre', 'Stage performances over time.'),
            new Indexable('partial', 'The history of bicycles', 'Urban transport.'),
        ]];
    }

    public function testExactSingleWordStillOutranksAMoreProminentInflection(): void
    {
        $finder = $this->finder([
            new Indexable('lemma', 'Петров', 'Петровы оставили документы. Петрова упоминали в переписке.'),
            new Indexable('exact', 'Семейный архив', 'Письмо Анны Петровой.'),
        ]);

        self::assertSame(['exact', 'lemma'], $this->ids($finder->find(new Query('петровой'))->getItems()));
    }

    public function testConnectorOnlyQueriesRemainSearchable(): void
    {
        $finder = $this->finder([
            new Indexable('found', 'Союзы', 'И'),
            new Indexable('other', 'Частицы', 'Не'),
        ]);

        self::assertSame(['found'], $this->ids($finder->find(new Query('и'))->getItems()));
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
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'ranking_test_');
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
