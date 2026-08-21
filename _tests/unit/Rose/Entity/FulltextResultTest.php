<?php

declare(strict_types = 1);

/**
 * @copyright 2022 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test\Entity;

use Codeception\Test\Unit;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\FulltextQuery;
use S2\Rose\Entity\FulltextResult;
use S2\Rose\Entity\ResultSet;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Storage\FulltextIndexContent;
use S2\Rose\Storage\FulltextIndexPositionBag;

/**
 * @group entity
 */
final class FulltextResultTest extends Unit
{
    public function testFrequencyReduction(): void
    {
        self::assertEqualsWithDelta(0.9889808283708308, FulltextResult::frequencyReduction(50, 2), PHP_FLOAT_EPSILON);
        self::assertEqualsWithDelta(0.17705374665950163, FulltextResult::frequencyReduction(50, 25), PHP_FLOAT_EPSILON);
        self::assertEquals(1, FulltextResult::frequencyReduction(3, 2));
    }

    public function testExactTitlePhraseOutranksTheSamePhraseInContent(): void
    {
        $words        = ['one', 'two', 'three', 'four', 'five'];
        $exactTitleId = new ExternalId('exact-title');
        $contentId    = new ExternalId('content');
        $indexContent = new FulltextIndexContent();

        foreach ($words as $position => $word) {
            $indexContent->add($word, new FulltextIndexPositionBag(
                $exactTitleId,
                [$position],
                [],
                [],
                \count($words),
                1.0,
            ));
            $indexContent->add($word, new FulltextIndexPositionBag(
                $contentId,
                [],
                [],
                [$position],
                \count($words),
                1.0,
            ));
        }

        $fulltextResult = new FulltextResult(
            new FulltextQuery($words, new PorterStemmerEnglish()),
            $indexContent,
            2,
        );
        $resultSet = new ResultSet();
        $fulltextResult->fillResultSet($resultSet);
        $resultSet->freeze();

        self::assertSame(
            [':exact-title', ':content'],
            array_keys($resultSet->getSortedRelevanceByExternalId()),
        );
    }
}
