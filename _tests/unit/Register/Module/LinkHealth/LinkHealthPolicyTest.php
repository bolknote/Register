<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\ArchiveStatus;
use Register\Module\LinkHealth\LinkHealthPolicy;
use Register\Module\LinkHealth\LinkHealthStatus;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\LinkProbeResult;
use Register\Module\LinkHealth\LinkTargetState;

final class LinkHealthPolicyTest extends Unit
{
    public function testSuccessfulResponseResetsFailuresAndSchedulesNormalInterval(): void
    {
        $now      = 1_800_000_000;
        $decision = (new LinkHealthPolicy())->decide(
            $this->target(LinkHealthStatus::SUSPECT, 2),
            new LinkProbeResult('https://example.test/final', 200),
            $now,
        );

        self::assertSame(LinkHealthStatus::HEALTHY, $decision->status);
        self::assertSame(0, $decision->failureCount);
        self::assertSame($now, $decision->lastSuccessAt);
        self::assertSame($now + LinkHealthPolicy::HEALTHY_INTERVAL, $decision->nextCheckAt);
        self::assertFalse($decision->lookupArchive);
    }

    public function testAccessDeniedMeansRestrictedRatherThanBroken(): void
    {
        $decision = (new LinkHealthPolicy())->decide(
            $this->target(LinkHealthStatus::SUSPECT, 2, 1_700_000_000),
            new LinkProbeResult('https://example.test/', 403),
            1_800_000_000,
        );

        self::assertSame(LinkHealthStatus::RESTRICTED, $decision->status);
        self::assertSame(0, $decision->failureCount);
        self::assertSame(1_700_000_000, $decision->lastSuccessAt);
        self::assertFalse($decision->lookupArchive);
    }

    public function testRequiresTwoHardFailuresBeforeArchiveLookup(): void
    {
        $policy = new LinkHealthPolicy();
        $first  = $policy->decide(
            $this->target(LinkHealthStatus::HEALTHY, 0),
            new LinkProbeResult('https://example.test/', 404),
            1_800_000_000,
        );
        $second = $policy->decide(
            $this->target(LinkHealthStatus::SUSPECT, $first->failureCount),
            new LinkProbeResult('https://example.test/', 404),
            1_800_086_400,
        );

        self::assertSame(LinkHealthStatus::SUSPECT, $first->status);
        self::assertFalse($first->lookupArchive);
        self::assertSame(LinkHealthStatus::BROKEN, $second->status);
        self::assertNull($second->nextCheckAt);
        self::assertTrue($second->lookupArchive);
    }

    public function testTransientFailureNeedsThreeConsecutiveAttempts(): void
    {
        $policy = new LinkHealthPolicy();
        $first  = $policy->decide(
            $this->target(LinkHealthStatus::HEALTHY, 0),
            new LinkProbeResult('https://example.test/', error: 'Timed out', errorReason: LinkProbeResult::ERROR_TIMEOUT),
            1_800_000_000,
        );
        $second = $policy->decide(
            $this->target(LinkHealthStatus::SUSPECT, 1),
            new LinkProbeResult('https://example.test/', 503),
            1_800_086_400,
        );
        $third = $policy->decide(
            $this->target(LinkHealthStatus::SUSPECT, 2),
            new LinkProbeResult('https://example.test/', 503),
            1_800_345_600,
        );

        self::assertSame(LinkHealthStatus::SUSPECT, $first->status);
        self::assertSame(LinkHealthStatus::SUSPECT, $second->status);
        self::assertSame(LinkHealthStatus::BROKEN, $third->status);
        self::assertTrue($third->lookupArchive);
    }

    public function testUnsafeDestinationIsBlockedWithoutArchiveLookup(): void
    {
        $decision = (new LinkHealthPolicy())->decide(
            $this->target(LinkHealthStatus::UNKNOWN, 0),
            new LinkProbeResult(
                'http://127.0.0.1/',
                error: 'Unsafe address',
                errorReason: LinkProbeResult::ERROR_UNSAFE,
            ),
            1_800_000_000,
        );

        self::assertSame(LinkHealthStatus::BLOCKED, $decision->status);
        self::assertNull($decision->nextCheckAt);
        self::assertFalse($decision->lookupArchive);
    }

    public function testUnavailableLocalResolverNeverTurnsAUrlIntoABrokenLink(): void
    {
        $now      = 1_800_000_000;
        $decision = (new LinkHealthPolicy())->decide(
            $this->target(LinkHealthStatus::SUSPECT, 2, 1_700_000_000),
            new LinkProbeResult(
                'https://example.test/',
                error: 'Resolver unavailable',
                errorReason: LinkProbeResult::ERROR_RESOLVER,
            ),
            $now,
        );

        self::assertSame(LinkHealthStatus::SUSPECT, $decision->status);
        self::assertSame(2, $decision->failureCount);
        self::assertSame($now + 3600, $decision->nextCheckAt);
        self::assertFalse($decision->lookupArchive);
    }

    /** @dataProvider statusesPreservedDuringLocalResolverFailure */
    public function testLocalResolverFailureDoesNotChangeRemoteLinkHealth(LinkHealthStatus $status): void
    {
        $now      = 1_800_000_000;
        $decision = (new LinkHealthPolicy())->decide(
            $this->target($status, 0),
            new LinkProbeResult(
                'https://example.test/',
                error: 'Resolver unavailable',
                errorReason: LinkProbeResult::ERROR_RESOLVER,
            ),
            $now,
        );

        self::assertSame($status, $decision->status);
        self::assertSame(0, $decision->failureCount);
        self::assertSame($now + 3600, $decision->nextCheckAt);
        self::assertFalse($decision->lookupArchive);
    }

    /** @return iterable<string, array{LinkHealthStatus}> */
    public static function statusesPreservedDuringLocalResolverFailure(): iterable
    {
        yield 'unchecked' => [LinkHealthStatus::UNKNOWN];
        yield 'healthy' => [LinkHealthStatus::HEALTHY];
        yield 'restricted' => [LinkHealthStatus::RESTRICTED];
    }

    private function target(
        LinkHealthStatus $status,
        int $failureCount,
        ?int $lastSuccessAt = null,
    ): LinkTargetState {
        return new LinkTargetState(
            1,
            'https://example.test/',
            LinkKind::EXTERNAL,
            $status,
            $failureCount,
            null,
            1_699_000_000,
            $lastSuccessAt,
            ArchiveStatus::UNCHECKED,
            null,
        );
    }
}
