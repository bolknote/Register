<?php

declare(strict_types = 1);

/** @noinspection PhpUnhandledExceptionInspection */

/**
 * @copyright 2016-2020 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Test\Storage;

use Codeception\Test\Unit;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\TocEntry;
use Register\Rose\Storage\File\SingleFileArrayStorage;

/**
 * @group storage
 * @group arr-storage
 */
final class SingleFileArrayStorageTest extends Unit
{
    private function getTempFilename(): string
    {
        return __DIR__ . '/../../../tmp/storage_test.php';
    }

    #[\Override]
    protected function _before(): void
    {
        @unlink($this->getTempFilename());
    }

    #[\Override]
    protected function _after(): void
    {
        if (is_file($this->getTempFilename())) {
            unlink($this->getTempFilename());
        }
    }

    public function testStorage(): void
    {
        $storage = new SingleFileArrayStorage($this->getTempFilename());

        $storage->load();

        $storage->addEntryToToc(
            new TocEntry('test title 1', '', new \DateTime(), '', 1, '4567890lkjhgfd'),
            new ExternalId('test_id_1')
        );
        $storage->addEntryToToc(
            new TocEntry('test title 2', '', new \DateTime(), '', 1, 'edfghj8765rfg'),
            new ExternalId('test_id_2')
        );

        $entry1 = $storage->getTocByExternalId(new ExternalId('test_id_1'));
        $entry2 = $storage->getTocByExternalId(new ExternalId('test_id_2'));
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry1);
        self::assertSame(1, $entry1->getInternalId());
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry2);
        self::assertSame(2, $entry2->getInternalId());

        $storage->addToFulltextIndex(['titleword'], ['keyword1', 'keyword2'], [1 => 'hello', 2 => 'world', 3=>'world'], new ExternalId('test_id_1'));

        $fulltextResult = $storage->fulltextResultByWords(['hello'], null);
        $info           = $fulltextResult->toArray()['hello'];
        self::assertArrayHasKey(':test_id_1', $info);
        self::assertEquals([1], $info[':test_id_1']->getContentPositions());
        self::assertEquals([], $info[':test_id_1']->getTitlePositions());
        self::assertEquals([], $info[':test_id_1']->getKeywordPositions());

        $fulltextResult = $storage->fulltextResultByWords(['world'], null);
        $info           = $fulltextResult->toArray()['world'];
        self::assertArrayHasKey(':test_id_1', $info);
        self::assertEquals([2, 3], $info[':test_id_1']->getContentPositions());
        self::assertEquals([], $info[':test_id_1']->getTitlePositions());
        self::assertEquals([], $info[':test_id_1']->getKeywordPositions());

        $storage->save();

        $storage = new SingleFileArrayStorage($this->getTempFilename());
        $storage->load();

        $entry1 = $storage->getTocByExternalId(new ExternalId('test_id_1'));
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry1);
        self::assertSame('test title 1', $entry1->getTitle());
        self::assertSame('4567890lkjhgfd', $entry1->getHash());

        $entry3 = $storage->getTocByExternalId(new ExternalId('test_id_3'));
        self::assertNotInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry3);

        $storage->addToFulltextIndex([], [], [10 => 'hello', 20 => 'world'], new ExternalId('test_id_2'));

        $fulltextResult = $storage->fulltextResultByWords(['world'], null);
        $info           = $fulltextResult->toArray()['world'];
        self::assertArrayHasKey(':test_id_1', $info);
        self::assertEquals([2, 3], $info[':test_id_1']->getContentPositions());
        self::assertArrayHasKey(':test_id_2', $info);
        self::assertEquals([20], $info[':test_id_2']->getContentPositions());

        $storage->save();
    }
}
