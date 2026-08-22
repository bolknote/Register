<?php

declare(strict_types = 1);

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/**
 * @copyright 2016-2024 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Test;

use Codeception\Test\Unit;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\TocEntryWithMetadata;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\MysqlRepository;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Rose\Storage\File\SingleFileArrayStorage;
use Register\Rose\Storage\StorageReadInterface;
use Register\Rose\Storage\StorageWriteInterface;

/**
 * @group int
 */
final class IntegrationTest extends Unit
{
    public const int TEST_FILE_NUM = 17;

    private function getTempFilename(): string
    {
        return __DIR__ . '/../../tmp/index2.php';
    }

    #[\Override]
    protected function _before(): void
    {
        @unlink($this->getTempFilename());
    }

    /**
     * @dataProvider indexableProvider
     *
     * @param Indexable[]           $indexables
     *
     * @throws \Exception
     */
    public function testFeatures(
        array                 $indexables,
        StorageReadInterface  $readStorage,
        StorageWriteInterface $writeStorage
    ): void {
        $stemmer = new PorterStemmerRussian(new PorterStemmerEnglish());
        $indexer = new Indexer($writeStorage, $stemmer);

        // We're working on an empty storage
        if ($writeStorage instanceof PdoStorage) {
            $writeStorage->erase();
        }

        foreach ($indexables as $indexable) {
            $indexer->index($indexable);
        }

        if ($writeStorage instanceof SingleFileArrayStorage) {
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        // Reinit storage
        if ($readStorage instanceof SingleFileArrayStorage) {
            $readStorage->load();
        }

        $finder = new Finder($readStorage, $stemmer);

        // Query 1
        $resultSet1 = $finder->find(new Query('snippets'));
        self::assertEquals([], $resultSet1->getSortedRelevanceByExternalId(), 'Do not index description');

        // Query 2
        $resultSet2 = $finder->find(new Query('content'));

        self::assertEquals([
            '20:id_2' => 2.5953804134970615,
            '20:id_1' => 0.12932092968696407,
            '10:id_1' => 0.08569157515491249,
        ], $resultSet2->getSortedRelevanceByExternalId());

        $items = $resultSet2->getItems();
        self::assertEquals('id_1', $items[2]->getId());
        self::assertEquals('10', $items[2]->getInstanceId());
        self::assertEquals('Test page title', $items[2]->getTitle());
        self::assertEquals('url1', $items[2]->getUrl());
        self::assertEquals('Description can be used in snippets', $items[2]->getDescription());
        self::assertEquals(new \DateTime('2016-08-24 00:00:00'), $items[2]->getDate());
        self::assertEqualsWithDelta(0.08569157515491249, $items[2]->getRelevance(), PHP_FLOAT_EPSILON);
        self::assertEquals('I have changed the <i>content</i>.', $items[2]->getSnippet());

        self::assertEqualsWithDelta(2.5953804134970615, $items[0]->getRelevance(), PHP_FLOAT_EPSILON);
        self::assertEquals(new \DateTime('2016-08-20 00:00:00+00:00'), $items[0]->getDate());
        self::assertEquals('This is the second page to be indexed. Let&#039;s compose something new.', $items[0]->getSnippet(), 'No snippets due to keyword match, no description provided, first sentences are used.');

        $resultSet2 = $finder->find((new Query('content'))->setLimit(2));

        self::assertEquals([
            '20:id_2' => 2.5953804134970615,
            '20:id_1' => 0.12932092968696407
        ], $resultSet2->getSortedRelevanceByExternalId());

        self::assertSame(3, $resultSet2->getTotalCount());

        $resultSet2 = $finder->find(new Query('content'));

        $resultItems = $resultSet2->getItems();
        self::assertCount(3, $resultItems);
        self::assertEqualsWithDelta(2.5953804134970615, $resultItems[0]->getRelevance(), PHP_FLOAT_EPSILON, 'Setting relevance ratio or sorting by relevance is not working');

        $resultSet2 = $finder->find(new Query('title'));
        self::assertEquals('id_1', $resultSet2->getItems()[0]->getId());
        self::assertEquals('Test page <i>title</i>', $resultSet2->getItems()[0]->getHighlightedTitle($stemmer));

        $resultSet2 = $finder->find((new Query('content'))->setInstanceId(10));
        self::assertCount(1, $resultSet2->getItems());
        self::assertEquals('id_1', $resultSet2->getItems()[0]->getId());
        self::assertEquals(10, $resultSet2->getItems()[0]->getInstanceId());

        $resultSet2 = $finder->find((new Query('content'))->setInstanceId(20));
        self::assertCount(2, $resultSet2->getItems());
        self::assertEquals('id_2', $resultSet2->getItems()[0]->getId());
        self::assertEquals(20, $resultSet2->getItems()[0]->getInstanceId());
        self::assertEquals('id_1', $resultSet2->getItems()[1]->getId());
        self::assertEquals(20, $resultSet2->getItems()[1]->getInstanceId());

        // Query 3
        $resultSet3 = $finder->find(new Query('сущность Plus'));
        self::assertEquals('id_3', $resultSet3->getItems()[0]->getId());
        self::assertEquals(
            'Тут есть тонкость - нужно проверить, как происходит экранировка в <i>сущностях</i> вроде + и &amp;<i>plus</i>;. Для этого нужно включить в текст само сочетание букв "<i>plus</i>".',
            $resultSet3->getItems()[0]->getSnippet()
        );
        self::assertEqualsWithDelta(18.35150247903209, $resultSet3->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        // Query 4
        $resultSet4 = $finder->find(new Query('эпл'));
        self::assertCount(1, $resultSet4->getItems());
        self::assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        self::assertEquals(
            'Например, красно-черный, <i>эпл</i>-вотчем, и другие интересные комбинации.',
            $resultSet4->getItems()[0]->getSnippet()
        );

        $finder->setHighlightTemplate('<b>%s</b>');
        $resultSet4   = $finder->find(new Query('красный заголовку'));
        $resultItems4 = $resultSet4->getItems();
        self::assertCount(1, $resultItems4);
        self::assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        self::assertEquals(
            'Например, <b>красно</b>-черный, эпл-вотчем, и другие интересные комбинации.',
            $resultItems4[0]->getSnippet()
        );
        self::assertEquals('id_3', $resultSet4->getItems()[0]->getId());
        self::assertEquals(
            'Русский текст. <b>Красным заголовком</b>. АБВГ',
            $resultItems4[0]->getHighlightedTitle($stemmer)
        );
        self::assertEqualsWithDelta(56.1069041483915, $resultSet4->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        // Query 5
        $resultSet5 = $finder->find(new Query('русский'));
        self::assertCount(1, $resultSet5->getItems());
        self::assertEqualsWithDelta(18.951204937870607, $resultSet5->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        $resultSet5 = $finder->find(new Query('русскому'));
        self::assertCount(1, $resultSet5->getItems());
        self::assertEqualsWithDelta(18.951204937870607, $resultSet5->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        $resultSet5 = $finder->find(new Query('абвг'));
        self::assertCount(1, $resultSet5->getItems());
        self::assertEqualsWithDelta(26.531686913018852, $resultSet5->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        // Query 6
        $resultSet6 = $finder->find(new Query('учитель не должен'));
        self::assertCount(1, $resultSet6->getItems());
        self::assertEqualsWithDelta(55.0961739079439, $resultSet6->getItems()[0]->getRelevance(), PHP_FLOAT_EPSILON);

        // Query 7: Test empty queries
        $resultSet7 = $finder->find(new Query(''));
        self::assertCount(0, $resultSet7->getItems());

        $resultSet7 = $finder->find(new Query("'")); // ' must be cleared
        self::assertCount(0, $resultSet7->getItems());

        // Query 8
        $resultSet8 = $finder->find(new Query('ціна'));
        self::assertEquals(
            'Например, в украинском есть слово <b>ціна</b>.',
            $resultSet8->getItems()[0]->getSnippet()
        );

        // Query 9
        $resultSet9 = $finder->find(new Query('7.0'));
        self::assertEquals(
            'Я не помню Windows 3.1, но помню Turbo Pascal <b>7.0</b>.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('7'));
        self::assertEquals(
            'В 1,<b>7</b> раз больше... Я не помню Windows 3.1, но помню Turbo Pascal <b>7</b>.0. Надо отдельно посмотреть, что ищется по одной цифре <b>7</b>...',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('Windows 3'));
        self::assertEquals(
            'Я не помню <b>Windows 3</b>.1, но помню Turbo Pascal 7.0.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('Windows 3.1'));
        self::assertEquals(
            'Я не помню <b>Windows 3.1</b>, но помню Turbo Pascal 7.0.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('Gallery'));
        self::assertEquals(
            'Или что-то может называться словом <b>Gallery</b>.',
            $resultSet9->getItems()[0]->getSnippet()
        );

        $resultSet9 = $finder->find(new Query('legacy'));
        self::assertEquals(
            'Some <b>legacy</b>. To be continued...',
            $resultSet9->getItems()[0]->getHighlightedTitle($stemmer)
        );

        // Query 10
        $resultSet10 = $finder->find(new Query('singlekeyword'));
        self::assertCount(1, $resultSet10->getItems());
        self::assertEquals('Description can be used in snippets', $resultSet10->getItems()[0]->getSnippet(), 'No snippets due to keyword match, description is used.');

        // Query 11
        $resultSet11 = $finder->find(new Query('images'));
        self::assertCount(1, $resultSet11->getItems());
        self::assertEquals(
            'Nothing is here but <b>images</b>: Alternative text',
            $resultSet11->getItems()[0]->getSnippet(),
        );
        $img0 = $resultSet11->getItems()[0]->getImageCollection()->offsetGet(0);
        self::assertNotNull($img0);
        self::assertEquals('1.jpg', $img0->getSrc());
        self::assertEquals('10', $img0->getWidth());
        self::assertEquals('15', $img0->getHeight());
        self::assertEquals('', $img0->getAlt());

        $img1 = $resultSet11->getItems()[0]->getImageCollection()->offsetGet(1);
        self::assertNotNull($img1);
        self::assertEquals('//i.upmath.me/svg/a%2Fb%20%3D%202024', $img1->getSrc());
        self::assertEquals('20', $img1->getWidth());
        self::assertEquals('25', $img1->getHeight());
        self::assertEquals('Alternative text', $img1->getAlt());

        // Query 12
        $resultSet12 = $finder->find(new Query('long_word_with_underscores'));
        self::assertCount(1, $resultSet12->getItems());
        self::assertEquals('Some sentence with <b>long_word_with_underscores</b>.', $resultSet12->getItems()[0]->getSnippet());

        // Empty result
        self::assertCount(0, $finder->find(new Query('..'))->getItems());
        self::assertCount(0, $finder->find(new Query('...'))->getItems());

        if ($readStorage instanceof PdoStorage && !str_starts_with($GLOBALS['register_rose_test_db']['dsn'], 'sqlite')) {
            $indexer->index(new Indexable('dummy', 'Dummy new', ''));
            $similarItems = $readStorage->getSimilar(new ExternalId('id_2', 20), false);
            self::assertInstanceOf(TocEntryWithMetadata::class, $similarItems[0]['tocWithMetadata']);
            self::assertEquals($right = [
                'toc_id'      => '1',
                'word_count'  => '16',
                'external_id' => 'id_1',
                'instance_id' => '10',
                'title'       => 'Test page title',
                'snippet'     => 'This is the first page to be indexed.',
                'snippet2'    => 'I have changed the content.',
            ], array_intersect_key($similarItems[0], $right));

            $similarItems = $readStorage->getSimilar(new ExternalId('id_2', 20), true);
            self::assertEquals($right = [
                'snippet' => 'This is the first page to be <i>indexed</i>.',
            ], array_intersect_key($similarItems[0], $right));

            $similarItems = $readStorage->getSimilar(new ExternalId('id_2', 20), false, 10);
            self::assertEquals($right = [
                'external_id' => 'id_1',
                'instance_id' => '10',
            ], array_intersect_key($similarItems[0], $right));

            $similarItems = $readStorage->getSimilar(new ExternalId('id_2', 20), false, 999);
            self::assertCount(0, $similarItems);
        }
    }

    /**
     * @dataProvider indexableProvider
     *
     * @param Indexable[]           $indexables
     *
     * @throws \RuntimeException
     */
    public function testParallelIndexingAndSearching(
        array                 $indexables,
        StorageReadInterface  $readStorage,
        StorageWriteInterface $writeStorage
    ): void {
        $stemmer = new PorterStemmerRussian();
        $indexer = new Indexer($writeStorage, $stemmer);

        // We're working on an empty storage
        if ($writeStorage instanceof PdoStorage) {
            $writeStorage->erase();
        }

        $indexer->index($indexables[0]);
        if ($writeStorage instanceof SingleFileArrayStorage) {
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        // Reinit storage
        if ($readStorage instanceof SingleFileArrayStorage) {
            $readStorage->load();
        }

        $finder    = new Finder($readStorage, $stemmer);
        $resultSet = $finder->find(new Query('page'));  // a word in $indexables[0]
        self::assertCount(1, $resultSet->getItems());

        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->load();
        }

        $indexer->index($indexables[1]);
        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        $resultSet = $finder->find(new Query('page')); // a word in $indexables[1]
        if (!($readStorage instanceof SingleFileArrayStorage)) {
            self::assertCount(2, $resultSet->getItems());
        }

        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->load();
        }

        $indexer->removeById($indexables[1]->getExternalId()->getId(), $indexables[1]->getExternalId()->getInstanceId());
        if ($writeStorage instanceof SingleFileArrayStorage) {
            // Wrap for updating the index
            $writeStorage->cleanup();
            $writeStorage->save();
        }

        $resultSet = $finder->find(new Query('page'));
        if (!($readStorage instanceof SingleFileArrayStorage)) {
            self::assertCount(1, $resultSet->getItems());
        }
    }

    public function testAutoErase(): void
    {
        global $register_rose_test_db;
        $pdo = new \PDO($register_rose_test_db['dsn'], $register_rose_test_db['username'], $register_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('DROP TABLE IF EXISTS ' . 'test_' . MysqlRepository::TOC);

        $pdoStorage = new PdoStorage($pdo, 'test_');
        $stemmer    = new PorterStemmerRussian();
        $indexer    = new Indexer($pdoStorage, $stemmer);
        $indexable  = new Indexable('id_1', 'Test page title', 'This is the first page to be <i>indexed</i>. I have to make up a content.', 10);

        $e = null;
        try {
            $indexer->index($indexable);
        } catch (EmptyIndexException $e) {
        }

        self::assertInstanceOf(\Register\Rose\Storage\Exception\EmptyIndexException::class, $e);

        $indexer->setAutoErase(true);
        $indexer->index($indexable);
    }

    /**
     * @return array<string, \Register\Rose\Storage\File\SingleFileArrayStorage[]|\Register\Rose\Entity\Indexable[][]|\Register\Rose\Storage\Database\PdoStorage[]>
     */
    public function indexableProvider(): array
    {
        $indexables = [
            (new Indexable('id_1', 'Test page title', 'This is the first page to be <i>indexed</i>. I have to make up a content.', 10))
                ->setKeywords('singlekeyword, multiple keywords')
                ->setDescription('Description can be used in snippets')
                ->setDate(new \DateTime('2016-08-24 00:00:00'))
                ->setUrl('url1')
            ,
            (new Indexable('id_2', 'Some legacy. To be continued...', "This is the second page to be indexed. Let's compose something new.", 20))
                ->setKeywords('content, ')
                ->setDescription('')
                ->setDate(new \DateTime('2016-08-20 00:00:00+00:00'))
                ->setUrl('any string')
                ->setRelevanceRatio(3.14)
            ,
            (new Indexable('id_3', 'Русский текст. Красным заголовком. АБВГ', '<p>Для проверки работы нужно написать побольше слов. В 1,7 раз больше. Вот еще одно предложение.</p><p>Тут есть тонкость - нужно проверить, как происходит экранировка в сущностях вроде &plus; и &amp;plus;. Для этого нужно включить в текст само сочетание букв "plus".</p><p>Еще одна особенность - наличие слов с дефисом. Например, красно-черный, эпл-вотчем, и другие интересные комбинации. Встречаются и другие знаки препинания, например, цифры. Я не помню Windows 3.1, но помню Turbo Pascal 7.0. Надо отдельно посмотреть, что ищется по одной цифре 7... Учитель не должен допускать такого...</p><p>А еще текст бывает на других языках. Например, в украинском есть слово ціна. Или что-то может называться словом Gallery.</p>', 20))
                ->setKeywords('ключевые слова, АБВГ')
                ->setDescription('')
                ->setDate(new \DateTime('2016-08-22 00:00:00'))
                ->setUrl('/якобы.урл')
            ,
            // overwrite the previous one
            (new Indexable('id_1', 'Test page title', 'This is the first page to be <i>indexed</i>. I have changed the content.', 10))
                ->setKeywords('singlekeyword, multiple keywords')
                ->setDescription('Description can be used in snippets')
                ->setDate(new \DateTime('2016-08-24 00:00:00'))
                ->setUrl('url1')
            ,
            new Indexable('id_1', 'Another instance', 'The same id but another instance. Word "content" is present here. Twice: content. Delimiters must be $...$ or  \[...\]', 20)
            ,
            new Indexable('id_4', 'Another instance', 'Some sentence with long_word_with_underscores. Nothing is here but images: <img src="1.jpg" width="10" height="15"> <img src="//i.upmath.me/svg/a%2Fb%20%3D%202024" width="20" height="25" alt="Alternative text" />', 20)
            ,
        ];

        global $register_rose_test_db;
        $pdo = new \PDO($register_rose_test_db['dsn'], $register_rose_test_db['username'], $register_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $filename = $this->getTempFilename();

        return [
            'files' => [$indexables, new SingleFileArrayStorage($filename), new SingleFileArrayStorage($filename)],
            'db'    => [$indexables, new PdoStorage($pdo, 'test_'), new PdoStorage($pdo, 'test_')],
        ];
    }
}
