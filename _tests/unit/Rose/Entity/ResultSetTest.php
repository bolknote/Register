<?php

declare(strict_types = 1);

/** @noinspection PhpUnhandledExceptionInspection */

/**
 * @copyright 2016-2024 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test\Entity;

use Codeception\Test\Unit;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\ResultSet;
use S2\Rose\Entity\Snippet;
use S2\Rose\Entity\SnippetLine;
use S2\Rose\Exception\ImmutableException;
use S2\Rose\Exception\UnknownIdException;
use S2\Rose\Stemmer\PorterStemmerEnglish;

/**
 * @group entity
 * @group result
 */
final class ResultSetTest extends Unit
{
    public function testLimit(): void
    {
        $result = $this->prepareResult(new ResultSet());
        $data   = $result->getSortedRelevanceByExternalId();
        self::assertCount(30, $data);

        $result = $this->prepareResult(new ResultSet(2));
        $data   = $result->getSortedRelevanceByExternalId();
        self::assertCount(2, $data);
        self::assertSame(30, $result->getTotalCount());
        self::assertEquals(39, $data[':id_29']);
        self::assertEquals(38, $data[':id_28']);

        $result = $this->prepareResult(new ResultSet(4, 3));
        $data   = $result->getSortedRelevanceByExternalId();
        self::assertCount(4, $data);
        self::assertSame(30, $result->getTotalCount());
        self::assertEquals(36, $data[':id_26']);
        self::assertEquals(35, $data[':id_25']);
        self::assertEquals(34, $data[':id_24']);
        self::assertEquals(33, $data[':id_23']);
    }

    public function testEmpty(): void
    {
        $resultSet = new ResultSet();
        $resultSet->freeze();

        $data = $resultSet->getItems();
        self::assertCount(0, $data);
    }

    public function testNotFrozenGetItems(): void
    {
        $this->expectException(ImmutableException::class);
        $resultSet = new ResultSet();
        $resultSet->getItems();
    }

    public function testNotFrozenAttachSnippet(): void
    {
        $this->expectException(UnknownIdException::class);
        $resultSet = new ResultSet();
        $resultSet->attachSnippet(new ExternalId('not found'), new Snippet('<i>%s</i>', new SnippetLine('', SnippetSource::FORMAT_PLAIN_TEXT, new PorterStemmerEnglish(), [], 0.0)));
    }

    public function testNotFrozenGetFoundExternalIds(): void
    {
        $this->expectException(ImmutableException::class);
        $resultSet = new ResultSet();
        $resultSet->getFoundExternalIds();
    }

    public function testNotFrozenGetFoundWordsByExternalId(): void
    {
        $this->expectException(ImmutableException::class);
        $resultSet = new ResultSet();
        $resultSet->getFoundWordPositionsByExternalId();
    }

    public function testNotFrozenGetSortedExternalIds(): void
    {
        $this->expectException(ImmutableException::class);
        $resultSet = new ResultSet();
        $resultSet->getSortedExternalIds();
    }

    public function testNotFrozenGetSortedRelevanceByExternalId(): void
    {
        $this->expectException(ImmutableException::class);
        $resultSet = new ResultSet();
        $resultSet->getSortedRelevanceByExternalId();
    }

    /**
     *
     * @throws ImmutableException
     * @throws \S2\Rose\Exception\InvalidArgumentException
     */
    private function prepareResult(ResultSet $result): ResultSet
    {
        for ($i = 30; $i--;) {
            $externalId = new ExternalId('id_' . $i);
            $result->addWordWeight('test1', $externalId, ['test' => $i]);
            $result->addWordWeight('test2', $externalId, ['test' => 10]);
        }

        $result->freeze();

        return $result;
    }
}
