<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\HistoricalContentViewPlan;

final class HistoricalContentViewPlanTest extends Unit
{
    public function testPreservesLifetimeTotalsAndKeepsTheAvailableDatedTail(): void
    {
        $plan = HistoricalContentViewPlan::build(
            [10 => 100, 11 => 5, 12 => 0],
            [
                ['content_id' => 10, 'day' => '2026-08-21', 'views' => 20],
                ['content_id' => 10, 'day' => '2026-08-22', 'views' => 10],
                ['content_id' => 10, 'day' => '2026-08-21', 'views' => 5],
                ['content_id' => 11, 'day' => '2026-08-22', 'views' => 5],
            ],
        );

        self::assertSame([
            ['content_id' => 10, 'day' => HistoricalContentViewPlan::UNDATED_HISTORY_DAY, 'views' => 65],
            ['content_id' => 10, 'day' => '2026-08-21', 'views' => 25],
            ['content_id' => 10, 'day' => '2026-08-22', 'views' => 10],
            ['content_id' => 11, 'day' => '2026-08-22', 'views' => 5],
        ], $plan);
    }

    public function testRejectsDatedCountsWhichExceedTheLifetimeTotal(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('exceed its lifetime total');

        HistoricalContentViewPlan::build(
            [10 => 2],
            [['content_id' => 10, 'day' => '2026-08-22', 'views' => 3]],
        );
    }

    public function testRejectsDatedCountsForUnknownContent(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown source content 11');

        HistoricalContentViewPlan::build(
            [10 => 2],
            [['content_id' => 11, 'day' => '2026-08-22', 'views' => 1]],
        );
    }

    public function testRejectsInvalidCalendarDay(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid UTC day');

        HistoricalContentViewPlan::build(
            [10 => 2],
            [['content_id' => 10, 'day' => '2026-02-30', 'views' => 1]],
        );
    }
}
