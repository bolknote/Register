<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Test;

use Codeception\Test\Unit;
use Codeception\Stub;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\Metadata\ImgCollection;
use Register\Rose\Entity\TocEntry;
use Register\Rose\Entity\TocEntryWithMetadata;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultItem;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\File\SingleFileArrayStorage;
use Register\Rose\Storage\FulltextIndexContent;
use Register\Rose\Storage\FulltextIndexPositionBag;
use Register\Rose\Storage\StorageReadInterface;

final class PaginationTest extends Unit
{
    /** @dataProvider backendProvider */
    public function testDatesDeterminePageMembershipBeforeSlicing(bool $pdo): void
    {
        $finder = $this->finder([
            (new Indexable('old', 'Example', 'pageterm'))->setDate(new \DateTime('2022-01-01 UTC')),
            (new Indexable('new', 'Example', 'pageterm'))->setDate(new \DateTime('2024-01-01 UTC')),
            (new Indexable('middle', 'Example', 'pageterm'))->setDate(new \DateTime('2023-01-01 UTC')),
        ], $pdo);
        $expected = ['new', 'middle', 'old'];

        self::assertSame($expected, $this->ids($finder->find(new Query('pageterm'))->getItems()));
        $paged = [];
        foreach (range(0, 2) as $offset) {
            $result = $finder->find((new Query('pageterm'))->setLimit(1)->setOffset($offset));
            self::assertSame(3, $result->getTotalCount());
            array_push($paged, ...$this->ids($result->getItems()));
        }

        self::assertSame($expected, $paged);
    }

    /** @dataProvider backendProvider */
    public function testAnEqualDateAndScoreUseAStableExternalId(bool $pdo): void
    {
        foreach ([['z', 'a', 'm'], ['m', 'a', 'z']] as $order) {
            $documents = array_map(static fn(string $id): Indexable =>
                (new Indexable($id, 'Example', 'pageterm'))->setDate(new \DateTime('2024-01-01 UTC')), $order);
            $finder = $this->finder($documents, $pdo);

            self::assertSame(['a', 'm', 'z'], $this->ids($finder->find(new Query('pageterm'))->getItems()));
            self::assertSame(['a', 'm'], $this->ids($finder->find((new Query('pageterm'))->setLimit(2))->getItems()));
            self::assertSame(['z'], $this->ids($finder->find((new Query('pageterm'))->setLimit(2)->setOffset(2))->getItems()));
        }
    }

    /** @dataProvider backendProvider */
    public function testAnUndatedResultDoesNotOutrankAHistoricalDate(bool $pdo): void
    {
        $finder = $this->finder([
            new Indexable('undated', 'Example', 'pageterm'),
            (new Indexable('historical', 'Example', 'pageterm'))->setDate(new \DateTime('1960-01-01 UTC')),
        ], $pdo);

        self::assertSame(['historical', 'undated'], $this->ids($finder->find(new Query('pageterm'))->getItems()));
        self::assertSame(['historical'], $this->ids($finder->find((new Query('pageterm'))->setLimit(1))->getItems()));
    }

    public function testDatesNeverOverrideRelevanceAndTimezonesCompareAsInstants(): void
    {
        $finder = $this->finder([
            (new Indexable('title', 'pageterm', 'Example'))->setDate(new \DateTime('2020-01-01 UTC')),
            (new Indexable('recent', 'Example', 'pageterm'))->setDate(new \DateTime('2025-01-01 UTC')),
        ], true);
        self::assertSame(['title'], $this->ids($finder->find((new Query('pageterm'))->setLimit(1))->getItems()));

        $finder = $this->finder([
            (new Indexable('earlier', 'Example', 'pageterm'))->setDate(new \DateTime('2024-01-01 12:00:00 +03:00')),
            (new Indexable('later', 'Example', 'pageterm'))->setDate(new \DateTime('2024-01-01 11:00:00 UTC')),
        ], true);
        self::assertSame(['later'], $this->ids($finder->find((new Query('pageterm'))->setLimit(1))->getItems()));
    }

    public function testOffsetsAreNonnegativeAndApplyWithoutALimit(): void
    {
        $finder = $this->finder([
            new Indexable('a', 'Example', 'pageterm'),
            new Indexable('b', 'Example', 'pageterm'),
            new Indexable('c', 'Example', 'pageterm'),
        ], true);

        self::assertSame(['a'], $this->ids($finder->find((new Query('pageterm'))->setLimit(1)->setOffset(-1))->getItems()));
        self::assertSame(['b', 'c'], $this->ids($finder->find((new Query('pageterm'))->setOffset(1))->getItems()));
        self::assertSame(['b', 'c'], $this->ids($finder->find((new Query('pageterm'))->setLimit(0)->setOffset(1))->getItems()));
    }

    public function testLegacyStorageWithoutPostingDatesStillSortsBeforePagination(): void
    {
        $storage = Stub::makeEmpty(StorageReadInterface::class, [
            'getTocSize' => static fn(): int => 2,
            'fulltextResultByWords' => static function (): FulltextIndexContent {
                $index = new FulltextIndexContent();
                foreach (['old', 'new'] as $id) {
                    $index->add('pageterm', new FulltextIndexPositionBag(new ExternalId($id), [0], [], [], 1, 1.0));
                }

                return $index;
            },
            'getTocByExternalIds' => static fn(ExternalIdCollection $ids): array => array_map(
                static fn(ExternalId $id): TocEntryWithMetadata => new TocEntryWithMetadata(
                    new TocEntry('pageterm', '', new \DateTime($id->getId() === 'new' ? '2024-01-01 UTC' : '2020-01-01 UTC'), '', 1.0, ''),
                    $id,
                    new ImgCollection(),
                ),
                $ids->toArray(),
            ),
        ]);
        $finder = new Finder($storage, new PorterStemmerEnglish());

        self::assertSame(['new'], $this->ids($finder->find((new Query('pageterm'))->setLimit(1))->getItems()));
    }

    /** @return \Iterator<string, array{bool}> */
    public static function backendProvider(): \Iterator
    {
        yield 'SQLite' => [true];
        yield 'file' => [false];
    }

    /** @param list<Indexable> $documents */
    private function finder(array $documents, bool $pdo): Finder
    {
        $storage = $pdo
            ? new PdoStorage(new \PDO('sqlite::memory:'), 'pagination_test_')
            : new SingleFileArrayStorage(__DIR__ . '/../../tmp/pagination-test.php');
        if ($storage instanceof PdoStorage) {
            $storage->erase();
        }

        $normalizer = new PorterStemmerEnglish();
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
