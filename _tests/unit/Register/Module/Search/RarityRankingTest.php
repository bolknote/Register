<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Core\Framework\Container;
use Register\Module\Search\Module;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\FulltextResult;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultItem;
use Register\Rose\Entity\ResultSet;
use Register\Rose\Exception\ImmutableException;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\Dto\SnippetQuery;
use Register\Rose\Storage\Dto\SnippetResult;
use Register\Rose\Storage\File\SingleFileArrayStorage;
use Register\Tools\Search\RelevanceBenchmark;

require_once dirname(__DIR__, 5) . '/tools/search/RelevanceBenchmark.php';

final class RarityRankingTest extends Unit
{
    /** @dataProvider storageProvider */
    public function testDefaultFinderOvercomesIncidentalCompleteMatches(string $storageType): void
    {
        $documents = [
            new Indexable('focused', 'Quartz guide', 'Specialized reference.'),
            new Indexable('complete', 'General notes', 'Quartz ' . str_repeat('unrelated ', 80) . 'archive.'),
        ];
        for ($i = 0; $i < 100; ++$i) {
            $documents[] = new Indexable('noise-' . $i, 'Archive', 'Common reference.');
        }

        $rarity = $this->finder($storageType, $documents)->find(new Query('quartz archive'), true);

        self::assertSame('focused', $rarity->getItems()[0]->getId());
        self::assertSame(102, $rarity->getTotalCount());
        self::assertSame(2, $rarity->getMaxMatchedQueryTerms());
        $trace = $rarity->getTrace()[':focused'];
        self::assertSame('rarity', $trace['rankingProfile']);
        self::assertSame(1, $trace['matchedQueryTerms']);
        self::assertGreaterThan(0.5, $trace['weightedQueryCoverage']);
        self::assertLessThan(1.0, $trace['weightedQueryCoverage']);
        self::assertGreaterThan(0.0, $trace['relevance']);
    }

    public function testApplicationContainerUsesRarityWithoutAnyOptIn(): void
    {
        $container = new Container(['db_prefix' => 'default_rarity_']);
        $container->set(\PDO::class, new \PDO('sqlite::memory:'));
        (new Module())->buildContainer($container);
        $storage = $container->get(PdoStorage::class);
        $storage->erase();

        $indexer = new Indexer($storage, $container->get(StemmerInterface::class));
        $indexer->index(new Indexable('focused', 'Quartz guide', 'Specialized reference.'));
        $indexer->index(new Indexable('complete', 'General notes', 'Quartz ' . str_repeat('unrelated ', 80) . 'archive.'));
        for ($i = 0; $i < 100; ++$i) {
            $indexer->index(new Indexable('noise-' . $i, 'Archive', 'Common reference.'));
        }

        $result = $container->get(Finder::class)->find((new Query('quartz archive'))->setLimit(1), true);
        self::assertSame('focused', $result->getItems()[0]->getId());
        self::assertSame(102, $result->getTotalCount());
        self::assertSame('rarity', $result->getTrace()[':focused']['rankingProfile']);
    }

    /** @dataProvider storageProvider */
    public function testLiteralSingleWordStillOutranksTitleInflections(string $storageType): void
    {
        $finder = $this->finder($storageType, [
            new Indexable('literal', 'General notes', 'Cat.'),
            new Indexable('inflection', 'Cats', str_repeat('cats ', 30)),
        ]);
        self::assertSame(['literal', 'inflection'], $this->ids($finder->find(new Query('cat'))->getItems()));
    }

    /** @dataProvider storageProvider */
    public function testPhrasesAreFilteredBeforePagingAndRemainInSnippets(string $storageType): void
    {
        $finder = $this->finder($storageType, [
            new Indexable('phrase', 'Example', 'A red blue ribbon hangs nearby. Quartz shines.'),
            new Indexable('inflected', 'Example', 'A red blue ribbons hang nearby. Quartz shines.'),
            new Indexable('separated', 'Quartz red blue', 'Extra title words. A red bright blue ribbon.'),
            new Indexable('cross-fields', 'Red', 'Blue ribbon and quartz.'),
        ])->setHighlightTemplate('<mark>%s</mark>');
        $query = '"red blue ribbon" quartz';
        $full = $finder->find(new Query($query));
        $page = $finder->find((new Query($query))->setLimit(1)->setOffset(1));

        self::assertSame(2, $full->getTotalCount());
        self::assertSame(2, $page->getTotalCount());
        self::assertSame(4, $page->getMaxMatchedQueryTerms());
        self::assertSame(array_slice($this->ids($full->getItems()), 1, 1), $this->ids($page->getItems()));
        self::assertStringContainsString('<mark>red blue', $page->getItems()[0]->getSnippet());
        self::assertStringContainsString('<mark>Quartz</mark>', $page->getItems()[0]->getSnippet());
        self::assertSame([], $finder->find(new Query('"missing phrase" quartz'))->getItems());
    }

    /** @dataProvider storageProvider */
    public function testDateAndIdTiesUseOneOrderAcrossPages(string $storageType): void
    {
        $finder = $this->finder($storageType, [
            new Indexable('undated', 'Quartz archive', 'Common reference.'),
            (new Indexable('old', 'Quartz archive', 'Common reference.'))->setDate((new \DateTime())->setTimestamp(-100)),
            (new Indexable('new-b', 'Quartz archive', 'Common reference.'))->setDate((new \DateTime())->setTimestamp(100)),
            (new Indexable('new-a', 'Quartz archive', 'Common reference.'))->setDate((new \DateTime())->setTimestamp(100)),
        ]);
        $full = $this->ids($finder->find(new Query('quartz archive'))->getItems());
        self::assertSame(['new-a', 'new-b', 'old', 'undated'], $full);

        $pages = [];
        foreach ([0, 2] as $offset) {
            $page = $finder->find((new Query('quartz archive'))->setLimit(2)->setOffset($offset));
            self::assertSame(4, $page->getTotalCount());
            array_push($pages, ...$this->ids($page->getItems()));
        }

        self::assertSame($full, $pages);
        self::assertSame(['old', 'undated'], $this->ids($finder->find((new Query('quartz archive'))->setOffset(2))->getItems()));
    }

    public function testAmbiguousLemmasAndRepeatedFieldsDoNotMultiplyCoverage(): void
    {
        $finder = $this->finder('sql', [
            (new Indexable('complete', 'Стали корабль', 'Сталь стала материалом. Корабль прибыл.'))->setKeywords('стали корабль'),
            new Indexable('partial', 'Стали', 'Сталь стала материалом.'),
        ]);
        $result = $finder->find(new Query('о стали и корабле'), true);
        $trace = $result->getTrace();

        self::assertSame(2, $trace[':complete']['matchedQueryTerms']);
        self::assertSame(1, $trace[':partial']['matchedQueryTerms']);
        self::assertSame(1.0, $trace[':complete']['weightedQueryCoverage']);
        self::assertSame(2, $result->getMaxMatchedQueryTerms());
        self::assertLessThan(1.0, $trace[':partial']['weightedQueryCoverage']);
    }

    public function testInstancesKeepIndependentDocumentFrequencies(): void
    {
        $finder = $this->finder('sql', [
            new Indexable('same', 'Quartz archive', 'Common reference.', 1),
            new Indexable('same', 'Quartz archive', 'Common reference.', 2),
            new Indexable('noise', 'Archive', 'Common reference.', 2),
        ]);
        $result = $finder->find((new Query('quartz archive'))->setInstanceId(1));
        self::assertSame(1, $result->getTotalCount());
        self::assertSame(1, $result->getItems()[0]->getInstanceId());
        self::assertSame(['same'], $this->ids($result->getItems()));
    }

    public function testMissingTermsAndConnectorOnlyQueriesStayFiniteAndSearchable(): void
    {
        $finder = $this->finder('sql', [new Indexable('found', 'Quartz', 'The reference.')]);
        foreach (['quartz missing', 'the', 'quartz 42'] as $query) {
            $result = $finder->find(new Query($query));
            self::assertSame(['found'], $this->ids($result->getItems()));
            self::assertTrue(is_finite($result->getItems()[0]->getRelevance()));
        }

        self::assertSame([], $finder->find(new Query('entirely absent'))->getItems());
    }

    public function testPositiveIdfHandlesTinyAndCommonCorpora(): void
    {
        self::assertSame(1.0, FulltextResult::rarityWeight(0, 0));
        self::assertSame(1.0, FulltextResult::rarityWeight(1, 100));
        self::assertGreaterThan(0.0, FulltextResult::rarityWeight(10000, 10000));
        self::assertGreaterThan(FulltextResult::rarityWeight(10000, 1000), FulltextResult::rarityWeight(10000, 10));
    }

    public function testRuntimeOrderMatchesTheOfflineProfile(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [
                ['id' => 'literal', 'title' => 'Cat quartz', 'content' => 'Archive.', 'keywords' => ''],
                ['id' => 'inflection', 'title' => 'Cats archive', 'content' => 'Quartz.', 'keywords' => ''],
                ['id' => 'other', 'title' => 'Archive', 'content' => 'Reference.', 'keywords' => ''],
            ],
            'queries' => [
                ['query' => 'cat', 'relevance' => ['literal' => 3]],
                ['query' => 'quartz archive', 'relevance' => ['literal' => 3]],
                ['query' => '"cat quartz"', 'relevance' => ['literal' => 3]],
            ],
        ]);
        foreach ($report['results'] as $result) {
            self::assertSame($result['rankings']['idf_soft'], $result['rankings']['rarity']);
        }
    }

    public function testBroadQueriesDoNotTruncateCandidatesAndFetchOnlyPageMetadata(): void
    {
        $storage = new class(new \PDO('sqlite::memory:'), 'rarity_reads_') extends PdoStorage {
            /** @var list<int> */
            private array $tocCounts = [];

            /** @var list<int> */
            private array $snippetCounts = [];

            public function resetReadCounts(): void
            {
                $this->tocCounts = [];
                $this->snippetCounts = [];
            }

            /** @return array{toc: list<int>, snippets: list<int>} */
            public function getReadCounts(): array
            {
                return ['toc' => $this->tocCounts, 'snippets' => $this->snippetCounts];
            }

            #[\Override]
            public function getTocByExternalIds(ExternalIdCollection $externalIds): array
            {
                $this->tocCounts[] = \count($externalIds->toArray());

                return parent::getTocByExternalIds($externalIds);
            }

            #[\Override]
            public function getSnippets(SnippetQuery $snippetQuery): SnippetResult
            {
                $this->snippetCounts[] = \count($snippetQuery->getExternalIds());

                return parent::getSnippets($snippetQuery);
            }
        };
        $storage->erase();

        $normalizer = new PorterStemmerEnglish();
        $indexer = new Indexer($storage, $normalizer);
        for ($i = 0; $i < 1001; ++$i) {
            $indexer->index(new Indexable(\sprintf('entry-%04d', $i), 'Quartz archive', 'Common reference.'));
        }

        $storage->resetReadCounts();

        $finder = new Finder($storage, $normalizer);
        $page = $finder->find((new Query('quartz archive'))->setLimit(1)->setOffset(1000));
        self::assertSame(1001, $page->getTotalCount());
        self::assertSame(['entry-1000'], $this->ids($page->getItems()));
        self::assertSame(['toc' => [1], 'snippets' => [1]], $storage->getReadCounts());
    }

    public function testRarityWeightsCannotBeMutatedAfterFreezing(): void
    {
        $result = (new ResultSet())->freeze();
        $this->expectException(ImmutableException::class);
        $result->setRarityQueryTermWeights([0 => 1.0]);
    }

    /** @dataProvider invalidWeightProvider */
    public function testInvalidRarityWeightsAreRejected(float $weight): void
    {
        $result = new ResultSet();
        $result->addWordWeight('quartz', new ExternalId('example'), ['score' => 1.0]);
        $this->expectException(\InvalidArgumentException::class);
        $result->setRarityQueryTermWeights([0 => $weight]);
    }

    /** @return \Iterator<string, array{string}> */
    public static function storageProvider(): \Iterator
    {
        yield 'SQL' => ['sql'];
        yield 'file' => ['file'];
    }

    /** @return \Iterator<string, array{float}> */
    public static function invalidWeightProvider(): \Iterator
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [acos(2.0)];
    }

    /** @param list<Indexable> $documents */
    private function finder(string $storageType, array $documents): Finder
    {
        $normalizer = new HybridWordNormalizer(
            new HistoricalRussianNormalizer(
                new ChurchSlavonicNormalizer(),
                new PreReformRussianNormalizer(),
                new OpenCorporaDictionary(dirname(__DIR__, 5) . '/_include/src/Register/Module/Search/resources/morphology/ru'),
            ),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $storage = $storageType === 'sql'
            ? new PdoStorage(new \PDO('sqlite::memory:'), 'rarity_test_')
            : new SingleFileArrayStorage(__DIR__ . '/../../../../tmp/rarity-ranking.php');
        if ($storage instanceof PdoStorage) {
            $storage->erase();
        }

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
