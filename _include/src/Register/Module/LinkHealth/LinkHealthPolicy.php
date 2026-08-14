<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final class LinkHealthPolicy
{
    public const int HEALTHY_INTERVAL = 30 * 86400;

    private const int FIRST_RETRY_DELAY = 86400;

    private const int LATER_RETRY_DELAY = 3 * 86400;

    private const int HARD_FAILURE_THRESHOLD = 2;

    private const int TRANSIENT_FAILURE_THRESHOLD = 3;

    public function decide(LinkTargetState $current, LinkProbeResult $probe, int $now): LinkHealthDecision
    {
        if ($probe->errorReason === LinkProbeResult::ERROR_UNSAFE) {
            return new LinkHealthDecision(
                LinkHealthStatus::BLOCKED,
                $current->failureCount,
                null,
                $current->lastSuccessAt,
                false,
            );
        }

        if ($probe->error === null && $probe->statusCode >= 200 && $probe->statusCode < 400) {
            return new LinkHealthDecision(
                LinkHealthStatus::HEALTHY,
                0,
                $now + self::HEALTHY_INTERVAL,
                $now,
                false,
            );
        }

        if ($probe->error === null && \in_array($probe->statusCode, [401, 403, 451], true)) {
            return new LinkHealthDecision(
                LinkHealthStatus::RESTRICTED,
                0,
                $now + self::HEALTHY_INTERVAL,
                $current->lastSuccessAt,
                false,
            );
        }

        $failures = $current->failureCount + 1;
        $hard     = \in_array($probe->statusCode, [404, 410], true)
            || $probe->errorReason === LinkProbeResult::ERROR_DNS;
        $threshold = $hard ? self::HARD_FAILURE_THRESHOLD : self::TRANSIENT_FAILURE_THRESHOLD;
        if ($failures >= $threshold) {
            return new LinkHealthDecision(
                LinkHealthStatus::BROKEN,
                $failures,
                null,
                $current->lastSuccessAt,
                true,
            );
        }

        return new LinkHealthDecision(
            LinkHealthStatus::SUSPECT,
            $failures,
            $now + ($failures === 1 ? self::FIRST_RETRY_DELAY : self::LATER_RETRY_DELAY),
            $current->lastSuccessAt,
            false,
        );
    }
}
