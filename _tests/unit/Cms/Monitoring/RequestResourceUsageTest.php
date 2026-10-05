<?php

declare(strict_types = 1);

namespace unit\Cms\Monitoring;

use PHPUnit\Framework\TestCase;
use Register\Core\Monitoring\RequestResourceUsage;

final class RequestResourceUsageTest extends TestCase
{
    public function testSubtractsProcessBaselineAndSeparatesStartupFromWaiting(): void
    {
        $clock = (object)['snapshot' => $this->usage(10_000_000, 2_000_000)];
        $usage = new RequestResourceUsage(100.0, $clock->snapshot, static fn(): array => $clock->snapshot);
        $clock->snapshot = $this->usage(10_003_000, 2_002_000);
        $usage->markBootstrap(100.8);
        $clock->snapshot = $this->usage(10_010_000, 2_006_000);
        self::assertSame([
            'cpu_ms' => 16.0,
            'cpu_user_ms' => 10.0,
            'cpu_system_ms' => 6.0,
            'bootstrap_ms' => 800.0,
            'bootstrap_cpu_ms' => 5.0,
        ], $usage->metrics());
    }

    public function testMissingCountersAreUnknownRatherThanZero(): void
    {
        $usage = new RequestResourceUsage(100.0, null, static fn(): ?array => null);
        self::assertNull($usage->metrics()['cpu_ms']);
        self::assertNull($usage->metrics()['bootstrap_cpu_ms']);
    }

    public function testNativeSystemCallFailureDoesNotBreakTheResponse(): void
    {
        $usage = new RequestResourceUsage(100.0, $this->usage(10_000_000, 2_000_000), static fn(): false => false);
        $usage->markBootstrap(100.1);
        self::assertNull($usage->metrics()['cpu_ms']);
        self::assertNull($usage->metrics()['bootstrap_cpu_ms']);
        self::assertSame(100.0, $usage->metrics()['bootstrap_ms']);
    }

    /** @return array<string,int> */
    private function usage(int $user, int $system): array
    {
        return [
            'ru_utime.tv_sec' => intdiv($user, 1_000_000),
            'ru_utime.tv_usec' => $user % 1_000_000,
            'ru_stime.tv_sec' => intdiv($system, 1_000_000),
            'ru_stime.tv_usec' => $system % 1_000_000,
        ];
    }
}
