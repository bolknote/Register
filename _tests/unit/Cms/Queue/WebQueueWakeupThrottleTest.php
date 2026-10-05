<?php

declare(strict_types = 1);

namespace unit\Cms\Queue;

use PHPUnit\Framework\TestCase;
use Register\Core\Queue\WebQueueWakeupThrottle;

final class WebQueueWakeupThrottleTest extends TestCase
{
    public function testSharedFileSkipsRepeatedWakeupsAndWaitsFromCompletion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register-wakeup-');
        self::assertIsString($path);
        $clock = (object)['now' => 1000.0];
        $calls = 0;
        $readClock = static fn(): float => $clock->now;
        $throttle = new WebQueueWakeupThrottle($path, $readClock);
        try {
            $throttle->run(30, static function () use (&$calls, $clock): void { ++$calls; $clock->now += 4.0; });
            self::assertSame(1, $calls);
            $anotherRequest = new WebQueueWakeupThrottle($path, $readClock);
            $clock->now = 1030.0;
            $anotherRequest->run(30, static function () use (&$calls): void { ++$calls; });
            self::assertSame(1, $calls);
            $clock->now = 1034.0;
            $anotherRequest->run(30, static function () use (&$calls): void { ++$calls; });
            self::assertSame(2, $calls);
        } finally {
            unlink($path);
        }
    }

    public function testConcurrentWakeupsDoNotWaitOrStartAnotherRunner(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register-wakeup-');
        self::assertIsString($path);
        $throttle = new WebQueueWakeupThrottle($path);
        $ran = false;
        try {
            $throttle->run(30, static function () use ($path, &$ran): void {
                (new WebQueueWakeupThrottle($path))->run(30, static function () use (&$ran): void { $ran = true; });
            });
            self::assertFalse($ran);
        } finally {
            unlink($path);
        }
    }

    public function testUnavailableLocalStorageDoesNotDisableTheDatabaseBackedRunner(): void
    {
        $calls = 0;
        (new WebQueueWakeupThrottle('/dev/null/register-wakeup.time'))->run(30, static function () use (&$calls): void { ++$calls; });
        self::assertSame(1, $calls);
    }

    public function testZeroCooldownBypassesTheFileThrottle(): void
    {
        $calls = 0;
        $throttle = new WebQueueWakeupThrottle('/dev/null/register-wakeup.time');
        for ($i = 0; $i < 2; ++$i) {
            $throttle->run(0, static function () use (&$calls): void { ++$calls; });
        }

        self::assertSame(2, $calls);
    }
}
