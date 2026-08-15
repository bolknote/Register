<?php

declare(strict_types = 1);

/** @noinspection PhpUnhandledExceptionInspection */

/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test;

use Codeception\Test\Unit;
use Codeception\Stub;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\ExternalIdCollection;
use S2\Rose\Entity\Metadata\ImgCollection;
use S2\Rose\Entity\Query;
use S2\Rose\Entity\TocEntry;
use S2\Rose\Entity\TocEntryWithMetadata;
use S2\Rose\Finder;
use S2\Rose\Stemmer\PorterStemmerRussian;
use S2\Rose\Storage\Dto\SnippetQuery;
use S2\Rose\Storage\Dto\SnippetResult;
use S2\Rose\Storage\FulltextIndexContent;
use S2\Rose\Storage\FulltextIndexPositionBag;
use S2\Rose\Storage\StorageReadInterface;

if (!class_exists(Stub::class)) {
    // Remove on dropping support for Codeception 4.x
    class_alias('Codeception\Util\Stub', Stub::class);
}

/**
 * @group finder
 */
final class FinderTest extends Unit
{
    public function testIgnoreFrequentWordsInFulltext(): void
    {
        $storedSnippetQuery = null;
        $storage = Stub::makeEmpty(StorageReadInterface::class, [
            'getTocSize'                      => static fn(): int => 30,
            'fulltextResultByWords'           => static function (array $words): \S2\Rose\Storage\FulltextIndexContent {
                $result = new FulltextIndexContent();
                foreach ($words as $k => $word) {
                    if ($word === 'find') {
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_3'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_2'), [], [1], [10, 20], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_1'), [1], [], [], 0, 1.0));
                    }

                    if ($word === 'and') {
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_1'), [], [], [4, 8], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_2'), [], [], [7, 11, 34], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_3'), [], [], [28, 65], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_4'), [], [], [45, 9], 0, 1.0));

                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_5'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_6'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_7'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_8'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_9'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_10'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_11'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_12'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_13'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_14'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_15'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_16'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_17'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_18'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_19'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_20'), [], [], [1], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_21'), [], [], [1], 0, 1.0));
                    }

                    if ($word === 'replace') {
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_2'), [], [], [12], 0, 1.0));
                        $result->add($word, new FulltextIndexPositionBag(new ExternalId('id_1'), [1], [], [], 0, 1.0));
                    }

                    unset($words[$k]);
                }

                if ($words !== []) {
                    throw new \RuntimeException(sprintf('Unknown words "%s" in StorageReadInterface stub.', implode(',', $words)));
                }

                return $result;
            },
            'getTocByExternalIds'             => static fn(ExternalIdCollection $ids): array => array_map(static fn(ExternalId $id): \S2\Rose\Entity\TocEntryWithMetadata => new TocEntryWithMetadata(
                new TocEntry('Title ' . $id->getId(), '', null, 'url_' . $id->getId(), 1, 'hash_' . $id->getId()),
                $id,
                new ImgCollection()
            ), $ids->toArray()),
            'getSnippets'                     => function (SnippetQuery $snippetQuery) use (&$storedSnippetQuery): SnippetResult {
                $storedSnippetQuery = $snippetQuery;
                return new SnippetResult();
            }
        ]);

        $stemmer   = new PorterStemmerRussian();
        $finder    = new Finder($storage, $stemmer);
        $resultSet = $finder->find(new Query('find and replace'));

        $items = $resultSet->getItems();
        self::assertCount(21, $items);

        $weights = $resultSet->getFoundWordPositionsByExternalId();
        self::assertCount(21, $weights);
        self::assertEquals([], $weights[':id_1']['find']);
        self::assertEquals([], $weights[':id_1']['replace']);
        self::assertEquals([10, 20], $weights[':id_2']['find']);
        self::assertEquals([12], $weights[':id_2']['replace']);
        self::assertEquals([1], $weights[':id_3']['find']);

        $query2 = new Query('find and replace');
        $query2->setLimit(10);

        $resultSet2 = $finder->find($query2);
        self::assertCount(10, $resultSet2->getItems());
        if (!$storedSnippetQuery instanceof SnippetQuery) {
            self::fail('Finder must pass a SnippetQuery to storage.');
        }

        self::assertCount(10, $storedSnippetQuery->getExternalIds());
    }
}
