<?php

declare(strict_types = 1);

/** @noinspection SqlDialectInspection */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/**
 * @copyright 2016-2020 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Test\Storage;

use Codeception\Test\Unit;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\ExternalIdCollection;
use Register\Rose\Entity\TocEntry;
use Register\Rose\Exception\RuntimeException;
use Register\Rose\Exception\UnknownIdException;
use Register\Rose\Storage\Database\AbstractRepository;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Rose\Storage\FulltextIndexPositionBag;

/**
 * @group storage
 * @group pdo
 */
final class PdoStorageTest extends Unit
{
    /** @psalm-suppress PropertyNotSetInConstructor Codeception initializes this property in _before(). */
    private \PDO $pdo;

    #[\Override]
    protected function _before(): void
    {
        global $register_rose_test_db;

        $this->pdo = $this->createPdo($register_rose_test_db);
    }

    public function testStorage(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $stat = $storage->getIndexStat();
        self::assertGreaterThan(0, $stat['bytes']);
        self::assertGreaterThanOrEqual(0, $stat['rows']);

        // Removing non-existent items
        $storage->removeFromToc(new ExternalId('id_10'));
        $storage->removeFromIndex(new ExternalId('id_10'));

        // Indexing
        $externalId1 = new ExternalId('id_1', 1);
        $externalId2 = new ExternalId('id_2', 2);

        $tocEntry1 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry1, $externalId1);

        $tocEntry2 = new TocEntry('', '', new \DateTime('2014-05-28'), '', 1, 'pokjhgtyuio');
        $storage->addEntryToToc($tocEntry2, $externalId2);

        $storage->addToFulltextIndex([], [], [1 => 'word1', 2 => 'word2'], $externalId1);
        $storage->addToFulltextIndex([], [], [1 => 'word2', 10 => 'word2'], $externalId2);

        $stat = $storage->getIndexStat();
        self::assertGreaterThan(0, $stat['bytes']);
        self::assertGreaterThanOrEqual(0, $stat['rows']);

        // Searching
        $fulltextResult = $storage->fulltextResultByWords(['word1']);
        self::assertEquals([
            '1:id_1' => new FulltextIndexPositionBag(new ExternalId('id_1', 1), [], [], [1], 0, 1.0)
        ], $fulltextResult->toArray()['word1']);

        $fulltextResult = $storage->fulltextResultByWords(['word2']);
        self::assertEquals([
            '1:id_1' => new FulltextIndexPositionBag(new ExternalId('id_1', 1), [], [], [2], 0, 1.0),
            '2:id_2' => new FulltextIndexPositionBag(new ExternalId('id_2', 2), [], [], [1, 10], 0, 1.0),
        ], $fulltextResult->toArray()['word2']);

        $fulltextResult = $storage->fulltextResultByWords(['word2'], 1);
        self::assertEquals([
            '1:id_1' => new FulltextIndexPositionBag(new ExternalId('id_1', 1), [], [], [2], 0, 1.0),
        ], $fulltextResult->toArray()['word2']);

        $fulltextResult = $storage->fulltextResultByWords(['word2'], 2);
        self::assertEquals([
            '2:id_2' => new FulltextIndexPositionBag(new ExternalId('id_2', 2), [], [], [1, 10], 0, 1.0),
        ], $fulltextResult->toArray()['word2']);

        $entry = $storage->getTocByExternalId($externalId2);
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry);
        self::assertSame($tocEntry2->getHash(), $entry->getHash());

        // Test updating
        $tocEntry3 = new TocEntry('', '', null, '', 1, 'jhg678o');
        $storage->addEntryToToc($tocEntry3, $externalId2);

        $entry = $storage->getTocByExternalId($externalId2);
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry);
        self::assertGreaterThan(0, $entry->getInternalId());
        self::assertSame($tocEntry3->getHash(), $entry->getHash());

        // Removing from index
        $storage->removeFromIndex($externalId2);
        $entry = $storage->getTocByExternalId($externalId2);
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry);

        $storage->removeFromToc($externalId2);
        self::assertCount(0, $storage->getTocByExternalIds(new ExternalIdCollection([$externalId2])));

        $fulltextResult = $storage->fulltextResultByWords(['word2']);
        self::assertEquals([
            '1:id_1' => new FulltextIndexPositionBag(new ExternalId('id_1', 1), [], [], [2], 0, 1.0),
        ], $fulltextResult->toArray()['word2']);

        // Reinit and...
        $storage = new PdoStorage($this->pdo, 'test_');

        // ... make sure the cache works properly
        self::assertCount(0, $storage->getTocByExternalIds(new ExternalIdCollection([$externalId2])));

        $fulltextResult = $storage->fulltextResultByWords(['word2']);
        self::assertEquals([
            '1:id_1' => new FulltextIndexPositionBag(new ExternalId('id_1', 1), [], [], [2], 0, 1.0),
        ], $fulltextResult->toArray()['word2']);

        // Remove id_1
        $entry = $storage->getTocByExternalId($externalId1);
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry);
        self::assertSame($tocEntry1->getHash(), $entry->getHash());

        $storage->removeFromIndex($externalId1);

        $entry = $storage->getTocByExternalId($externalId1);
        self::assertInstanceOf(\Register\Rose\Entity\TocEntry::class, $entry);

        $storage->removeFromToc($externalId1);
        self::assertCount(0, $storage->getTocByExternalIds(new ExternalIdCollection([$externalId1])));
    }

    public function testParallelProcesses(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $storage2 = new PdoStorage($this->pdo, 'test_');

        $externalId = new ExternalId('id_1');

        // Indexing
        $tocEntry1 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry1, $externalId);

        // Race condition
        $tocEntry1mod = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '9654321');
        $storage2->addEntryToToc($tocEntry1mod, $externalId);

        $tocEntry2mod = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '111111');
        $storage->addEntryToToc($tocEntry2mod, $externalId);

        $storedEntry = $storage2->getTocByExternalId($externalId);
        self::assertInstanceOf(TocEntry::class, $storedEntry);
        self::assertEquals('111111', $storedEntry->getHash());
    }

    public function testUpdateToc(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $storage->erase();

        $externalId = new ExternalId('id_1');

        $tocEntry1 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry1, $externalId);
        $storedEntry = $storage->getTocByExternalId($externalId);
        self::assertInstanceOf(TocEntry::class, $storedEntry);
        self::assertEquals('123456789', $storedEntry->getHash());

        $tocEntry1mod = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '9654321');
        $storage->addEntryToToc($tocEntry1mod, $externalId);
        $storedEntry = $storage->getTocByExternalId($externalId);
        self::assertInstanceOf(TocEntry::class, $storedEntry);
        self::assertEquals('9654321', $storedEntry->getHash());
    }

    public function testAddToSingleKeywordIndex(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $tocEntry = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry, new ExternalId('id_1'));

        $tocEntry2 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry2, new ExternalId('id_2'));

        $tocEntry3 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry3, new ExternalId('id_3'));

        $storage->addToFulltextIndex(['type1'], [], [], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], ['type2'], [], new ExternalId('id_1'));
        $storage->addToFulltextIndex(['type1'], [], [], new ExternalId('id_2'));
        $storage->addToFulltextIndex(['type1'], [], [], new ExternalId('id_3'));
        $storage->addToFulltextIndex(['type1-1'], [], [], new ExternalId('id_1'));

        $data = $storage->fulltextResultByWords(['type1', 'type2']);
        self::assertCount(2, $data->toArray());

        $result = [];
        foreach ($data->toArray()['type1'] as $positionBag) {
            $result[] = [$positionBag->getExternalId(), $positionBag->getTitlePositions(), $positionBag->getKeywordPositions()];
        }

        self::assertCount(3, $result);
        self::assertEquals('id_1', $result[0][0]->getId());
        self::assertCount(1, $result[0][1]);

        $result = [];
        foreach ($data->toArray()['type2'] as $keywordPositionBag) {
            $result[] = [$keywordPositionBag->getExternalId(), $keywordPositionBag->getTitlePositions(), $keywordPositionBag->getKeywordPositions()];
        }

        self::assertCount(1, $result);
        self::assertEquals('id_1', $result[0][0]->getId());
        self::assertCount(1, $result[0][2]);
    }

    public function testDiacritic(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $storage->addEntryToToc(
            new TocEntry('title 1', 'descr 1', new \DateTime('2014-05-28'), '', 1, '123456789'),
            new ExternalId('id_1')
        );
        $storage->addToFulltextIndex([], [], ['Flugel', 'Shlomo', 'Tormented'], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], [], ['Flügel', 'Shlømo', 'Tørmented'], new ExternalId('id_1'));
    }

    public function testLongWords(): void
    {
        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $storage->addEntryToToc(
            new TocEntry('title 1', 'descr 1', new \DateTime('2014-05-28'), '', 1, '123456789'),
            new ExternalId('id_1')
        );

        $storage->addEntryToToc(
            new TocEntry('title 2', 'descr 2', new \DateTime('2014-05-28'), '', 1, '987654321'),
            new ExternalId('id_2')
        );

        $storage->addToFulltextIndex([], [], ['word', 'iu9304809n87908p08309xm8938noue09x78349c7m3098kx09237498xn89738j9457xp98q754891209834xm928349o7978x94987n89o7908x98984390n2cj347x89793857c9879oxieru9084920x83497nm37nosaujwaeuj034iroefjj98r3epw8cim9or8439urno9eufoluia039480pifou93'], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], [], ['word', 'iu9304809n87908p08309xm8938noue09x78349c7m3098kx09237498xn89738j9457xp98q754891209834xm928349o7978x94987n89o7908x98984390n2cj347x89793857c9879oxieru9084920x83497nm37nosaujwaeuj034iroefjj98r3epw8cim9or8439urno9eufoluia039480pifou93'], new ExternalId('id_2'));

        $storage->addToFulltextIndex([], [], ['word2', '9siufiai279837jz972q39z78qao298m3apq8n9283j298cnq08498908ks09809r8mc9o90q7808sdolfjlis39w8kso0sdu87j934797239478o7o3j4d573p985jkdx37oc8so89o3849os8l948o9l8884iu9304809n87908p08309xm8938noue09x78349c7m3098kx09237498xn89738j9457xp98q754891209834xm928349o7978x94987n89o7908x98984390n2cj347x89793857c9879oxieru9084920x83497nm37nosaujwaeuj034iroefjj98r3epw8is8ajpk9xox8jo9834k0ax8k4r9o8wk9o38rmoc8mo95m8co83km898madkjflikjiuroiuiweru0198390u90qu0p98784kqz8p94xco8mcim9or8439urno9eufoluia039480pifou93'], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], [], ['word2', '9siufiai279837jz972q39z78qao298m3apq8n9283j298cnq08498908ks09809r8mc9o90q7808sdolfjlis39w8kso0sdu87j934797239478o7o3j4d573p985jkdx37oc8so89o3849os8l948o9l8884iu9304809n87908p08309xm8938noue09x78349c7m3098kx09237498xn89738j9457xp98q754891209834xm928349o7978x94987n89o7908x98984390n2cj347x89793857c9879oxieru9084920x83497nm37nosaujwaeuj034iroefjj98r3epw8is8ajpk9xox8jo9834k0ax8k4r9o8wk9o38rmoc8mo95m8co83km898madkjflikjiuroiuiweru0198390u90qu0p98784kqz8p94xco8mcim9or8439urno9eufoluia039480pifou93'], new ExternalId('id_2'));

        $storage->addToFulltextIndex([], [], ['word21',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt0',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt1',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt1',
        ], new ExternalId('id_1'));

        $storage->addToFulltextIndex([], [], ['word21',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt0',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt0',
            'wwjfau8wmtbmse9uvlr2ynrlkzlvdhe3mvgytjvls1jkvm1qmjvnk2jsbeYxcvznqk9or3a4vu1luzzwy1lcevltndvsskfmufnsrwt3cxduYytxqlhnyk5bbnvozkttmujlrwqyawxfexrsr1zcvzvnsgjru0tlvvr0mjryr2hxbxpaznrnywrzv0vnbmpjdja5t1rzzefkallmmevysva3zkv3cju5dvvazjbmaju5bdixvkewbuqvsuvywgdqatc5wejyt2tvntvswwx1tezhqxb1l3dkuxl5awpoqllev245vstiajfddxphwfqxvgvpegjdv3jseu9lbe1vqmxhrklla3bsrm9xukntakirwxldc3i5zjdzoggwymplmfpgrgrxkzg3qtjfsgpknwh5rmdxzzhptxvvtuv5sfznm2dznhvqwkjratlwdmhkcleYnvndshjsvkzzevpbagc1zmq0nlhlsg43ynvhruvdl0zmuhvielnhrkrzsvfyls05ukjqm24ym0d4bjfbrwfvqjlyszjnpt1',
        ], new ExternalId('id_2'));

        $storage->addToFulltextIndex([], [], ['word3', '1' . str_repeat('ю', 200)], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], [], ['word3', '1' . str_repeat('ю', 200)], new ExternalId('id_2'));

        $storage->addToFulltextIndex([], [], ['word4', '1' . str_repeat('я', 255)], new ExternalId('id_1'));
        $storage->addToFulltextIndex([], [], ['word4', '1' . str_repeat('я', 255)], new ExternalId('id_2'));
    }

    public function testParallelAddingInTransactions(): void
    {
        global $register_rose_test_db;

        $pdo2 = $this->createPdo($register_rose_test_db);

        $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driverName === 'mysql') {
            $pdo2->exec('set innodb_lock_wait_timeout=0;');
            $this->pdo->exec('set innodb_lock_wait_timeout=0;');
        } elseif ($driverName === 'pgsql') {
            $pdo2->exec('SET lock_timeout = 1;'); // 1 ms
            $this->pdo->exec('SET lock_timeout = 1;'); // 1 ms
        } elseif ($driverName === 'sqlite') {
            $pdo2->setAttribute(\PDO::ATTR_TIMEOUT, 0);
            $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 0);
        }

        $storage = new PdoStorage($this->pdo, 'test_tr_');
        $storage->erase();

        $storage->startTransaction();
        $storage->addEntryToToc(
            new TocEntry('title 1', 'descr 1', new \DateTime('2014-05-28'), '', 1, '123456789'),
            new ExternalId('id_1')
        );
        $storage->addToFulltextIndex([], [], ['word1', 'word2', 'word3'], new ExternalId('id_1'));

        $storage2 = new PdoStorage($pdo2, 'test_tr_');
        $storage2->startTransaction();

        $caughtException = null;
        try {
            $storage2->addEntryToToc(
                new TocEntry('title 2', 'descr 2', new \DateTime('2014-05-28'), '', 1, 'qwerty'),
                new ExternalId('id_2')
            );

            if ($driverName !== 'sqlite') {
                $storage2->addToFulltextIndex([], [], ['word1', 'word5'], new ExternalId('id_2'));
            }
        } catch (RuntimeException $e) {
            $caughtException = $e;
        } finally {
            if ($pdo2->inTransaction()) {
                $pdo2->rollBack();
            }

            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        self::assertInstanceOf(RuntimeException::class, $caughtException);
        self::assertStringContainsString(
            $driverName === 'sqlite' ? 'Cannot insert new items. Possible deadlock?' : 'Cannot insert words. Possible deadlock?',
            $caughtException->getMessage()
        );
    }

    public function testParallelAddingAndErasingInTransactions(): void
    {
        global $register_rose_test_db;

        $pdo2 = $this->createPdo($register_rose_test_db);

        $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driverName === 'mysql') {
            $pdo2->exec('set lock_wait_timeout=1;'); // 1s
            $this->pdo->exec('set lock_wait_timeout=1;'); // 1s
        } elseif ($driverName === 'pgsql') {
            $pdo2->exec('SET lock_timeout = 1;'); // 1 ms
            $this->pdo->exec('SET lock_timeout = 1;'); // 1 ms
        } elseif ($driverName === 'sqlite') {
            $pdo2->setAttribute(\PDO::ATTR_TIMEOUT, 0);
            $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 0);
        }

        $repo = AbstractRepository::create($this->pdo, 'test_tr_');
        $repo->startTransaction();
        $repo->insertWords(['word4', 'word5', 'word6']);

        $storage2 = new PdoStorage($pdo2, 'test_tr_');

        $caughtException = null;
        try {
            $storage2->erase();
        } catch (RuntimeException $e) {
            $caughtException = $e;
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        self::assertInstanceOf(RuntimeException::class, $caughtException);
        self::assertStringContainsString('Cannot drop and create tables. Possible deadlock? Database reported:', $caughtException->getMessage());
    }

    public function testBrokenDb(): void
    {
        $this->expectException(EmptyIndexException::class);

        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $storage->addEntryToToc(
            new TocEntry('title 1', 'descr 1', new \DateTime('2014-05-28'), '', 1, '123456789'),
            new ExternalId('id_1')
        );

        $this->pdo->exec('DROP TABLE test_fulltext_index;');

        $storage->removeFromIndex(new ExternalId('id_1'));
    }

    public function testBrokenDbFulltextResultForWords(): void
    {
        $this->expectException(EmptyIndexException::class);

        $storage = new PdoStorage($this->pdo, 'test_');
        $storage->erase();

        $this->pdo->exec('ALTER TABLE test_fulltext_index DROP COLUMN positions');

        $storage->fulltextResultByWords(['word']);
    }

    public function testNonExistentDbAddToToc(): void
    {
        $this->expectException(EmptyIndexException::class);

        $storage   = new PdoStorage($this->pdo, 'non_existent_');
        $tocEntry1 = new TocEntry('test title', 'descr', new \DateTime('2014-05-28'), '', 1, '123456789');
        $storage->addEntryToToc($tocEntry1, new ExternalId('id_1'));
    }

    public function testNonExistentDbAddToFulltext(): void
    {
        $this->expectException(UnknownIdException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->addToFulltextIndex([], [], ['word'], new ExternalId('id_1'));
    }

    public function testNonExistentDbAddToSingleKeywordIndex(): void
    {
        $this->expectException(UnknownIdException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->addToFulltextIndex([], ['keyword'], [], new ExternalId('id_1'));
    }

    public function testNonExistentDbAddToMultipleKeywordIndex(): void
    {
        $this->expectException(UnknownIdException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->addToFulltextIndex([], ['multi keyword'], [], new ExternalId('id_1'));
    }

    public function testNonExistentDbGetTocByExternalIds(): void
    {
        $this->expectException(EmptyIndexException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->getTocByExternalIds(new ExternalIdCollection([new ExternalId('id_1')]));
    }

    public function testNonExistentDbGetTocSize(): void
    {
        $this->expectException(EmptyIndexException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->getTocSize(null);
    }

    public function testNonExistentDbFillFulltextResultForWords(): void
    {
        $this->expectException(EmptyIndexException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->fulltextResultByWords(['word']);
    }

    public function testNonExistentDbGetSimilar(): void
    {
        if (str_starts_with($GLOBALS['register_rose_test_db']['dsn'], 'sqlite')) {
            $this->expectException(\LogicException::class);
        } else {
            $this->expectException(EmptyIndexException::class);
        }

        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->getSimilar(new ExternalId('id_1'), true);
    }

    public function testNonExistentDbRemoveFromToc(): void
    {
        $this->expectException(EmptyIndexException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->removeFromToc(new ExternalId('id_1'));
    }

    public function testNonExistentDbRemoveFromIndex(): void
    {
        $this->expectException(EmptyIndexException::class);
        $storage = new PdoStorage($this->pdo, 'non_existent_');
        $storage->removeFromIndex(new ExternalId('id_1'));
    }

    /**
     * @param array<string, mixed> $register_rose_test_db
     */
    private function createPdo(array $register_rose_test_db): \PDO
    {
        $pdo = new \PDO($register_rose_test_db['dsn'], $register_rose_test_db['username'], $register_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
