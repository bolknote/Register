<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Queue;

use Codeception\Test\Unit;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueTimeBudgetExceeded;

final class QueueExecutionBudgetTest extends Unit
{
    public function testUsesMonotonicDeadlineAndRequiredStartReserve(): void
    {
        $clock  = new MutableMonotonicClock();
        $budget = new QueueExecutionBudget(1.0, $clock(...));

        self::assertSame(1.0, $budget->remainingSeconds());
        self::assertTrue($budget->canStart(0.75));

        $clock->now += 0.8;
        self::assertEqualsWithDelta(0.2, $budget->remainingSeconds(), 0.000_001);
        self::assertFalse($budget->canStart(0.25));

        $clock->now += 0.2;
        self::assertSame(0.0, $budget->remainingSeconds());
        self::assertFalse($budget->canStart());

        $this->expectException(QueueTimeBudgetExceeded::class);
        $budget->checkpoint();
    }

    /** @dataProvider invalidDurationProvider */
    public function testRejectsInvalidDuration(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QueueExecutionBudget($seconds);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidDurationProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [\acos(2.0)];
    }
}

final class MutableMonotonicClock
{
    public float $now = 1.0;

    public function __invoke(): float
    {
        return $this->now;
    }
}
