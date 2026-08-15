<?php

declare(strict_types = 1);

/**
 * @copyright 2022 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test\Entity;

use Codeception\Test\Unit;
use S2\Rose\Entity\FulltextResult;

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
}
