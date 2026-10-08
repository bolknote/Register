<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Test\Storage;

use Codeception\Test\Unit;
use Register\Rose\Entity\Indexable;
use Register\Rose\Indexer;
use Register\Rose\Finder;
use Register\Rose\Entity\Query;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\File\SingleFileArrayStorage;

final class IndexWordLookupTest extends Unit
{
    /** @dataProvider transactionProvider */
    public function testAnOuterRollbackDoesNotLeaveCachedWordIdsForTheNextTransaction(bool $external): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $storage = new PdoStorage($pdo, 'rollback_words_');
        $storage->erase();

        $normalizer = new PorterStemmerEnglish();
        $indexer = new Indexer($storage, $normalizer);
        $pdo->beginTransaction();
        $indexer->index(new Indexable('rolled-back', 'commonword', 'first second third'));
        $pdo->rollBack();

        if ($external) {
            $pdo->beginTransaction();
        }

        $indexer->index(new Indexable('committed', 'commonword', 'different'));
        if ($external) {
            $pdo->commit();
        }

        $finder = new Finder($storage, $normalizer);
        self::assertSame(1, $finder->find(new Query('commonword'))->getTotalCount());
        self::assertSame(0, $finder->find(new Query('first'))->getTotalCount());
    }

    /** @return \Iterator<string, array{bool}> */
    public static function transactionProvider(): \Iterator
    {
        yield 'external transaction follows' => [true];
        yield 'owned transaction follows' => [false];
    }

    public function testLargeVocabularyRequestsAreBatched(): void
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'batch_lookup_');
        $storage->erase();

        (new Indexer($storage, new PorterStemmerEnglish()))->index(new Indexable('found', 'Present', ''));
        $words = array_map(static fn(int $i): string => ':exact:missing' . $i, range(0, 299));
        $words[] = ':exact:present';

        self::assertSame([':exact:present'], $storage->findExistingIndexWords($words));
    }

    /** @dataProvider storageProvider */
    public function testOnlyActiveWordsInTheRequestedInstanceAreReturned(PdoStorage|SingleFileArrayStorage $storage): void
    {
        if ($storage instanceof PdoStorage) {
            $storage->erase();
        }

        $indexer = new Indexer($storage, new PorterStemmerEnglish());
        $indexer->index(new Indexable('first', 'Alpha', '', 1));
        $indexer->index(new Indexable('second', 'Beta', '', 2));

        self::assertEqualsCanonicalizing([':exact:alpha', ':exact:beta'], $storage->findExistingIndexWords([':exact:alpha', ':exact:beta', ':exact:missing']));
        self::assertSame([':exact:alpha'], $storage->findExistingIndexWords([':exact:alpha', ':exact:beta'], 1));
        self::assertCount(1, $storage->findExistingIndexWords([':exact:alpha', ':exact:beta'], null, 1));
        self::assertSame([], $storage->findExistingIndexWords([':exact:alpha'], null, 0));
        $indexer->removeById('first', 1);
        self::assertSame([], $storage->findExistingIndexWords([':exact:alpha']));
    }

    /** @return \Iterator<string, array{PdoStorage|SingleFileArrayStorage}> */
    public static function storageProvider(): \Iterator
    {
        yield 'SQLite' => [new PdoStorage(new \PDO('sqlite::memory:'), 'word_lookup_')];
        yield 'file' => [new SingleFileArrayStorage(__DIR__ . '/../../../tmp/word-lookup.php')];
    }
}
