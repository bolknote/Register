<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Module\Analytics\AnalyticsEvent;
use Register\Module\Analytics\AnalyticsSpool;
use Symfony\Component\Filesystem\Filesystem;

final class AnalyticsSpoolTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_analytics_spool_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAppendsSealsReadsAndRemovesARecoverableSegment(): void
    {
        $spool = new AnalyticsSpool($this->directory, minimumSegmentAge: 0, shards: 1);
        $now   = 1788100000;
        $first = $this->event(str_repeat('1', 32), $now);
        $last  = $this->event(str_repeat('2', 32), $now + 1);

        $spool->append([$first, $last], $now);

        self::assertTrue($spool->hasDueWork($now));
        self::assertSame(1, $spool->sealDue($now));
        $segments = $spool->sealedSegments();
        self::assertCount(1, $segments);

        $batch = $spool->readSegment($segments[0]);
        self::assertSame(0, $batch['invalid']);
        self::assertSame([$first->toArray(), $last->toArray()], array_map(
            static fn(AnalyticsEvent $event): array => $event->toArray(),
            $batch['events'],
        ));

        $spool->removeSegment($segments[0]);
        self::assertFalse($spool->hasDueWork($now));
    }

    public function testKeepsOneSessionInOneShard(): void
    {
        $spool = new AnalyticsSpool($this->directory, minimumSegmentAge: 0, shards: 4);
        $now   = 1788100000;

        $spool->append([
            $this->event(str_repeat('0', 32), $now),
            $this->event(str_repeat('0', 31) . '1', $now + 1),
        ], $now);

        self::assertSame(1, $spool->sealDue($now));
        self::assertCount(1, $spool->sealedSegments());
    }

    private function event(string $id, int $time): AnalyticsEvent
    {
        return new AnalyticsEvent(
            $id,
            AnalyticsEvent::TYPE_PAGE_VIEW,
            $time,
            $time,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 32),
            str_repeat('d', 64),
            '/post/example',
            'Example',
            str_repeat('e', 64),
            'direct',
            '',
            '',
            '',
            '',
            '',
            0,
            0,
            '{}',
        );
    }
}
