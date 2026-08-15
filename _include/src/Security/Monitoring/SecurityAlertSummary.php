<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Security\Monitoring;

final readonly class SecurityAlertSummary
{
    public function __construct(
        public int  $windowMinutes,
        public int  $unauthorizedResponses,
        public int  $forbiddenResponses,
        public int  $rateLimitedResponses,
        public int  $cspViolations,
        public int  $uploadFailures,
        public bool $unauthorizedSpike,
        public bool $forbiddenSpike,
        public bool $rateLimitedSpike,
        public bool $cspSpike,
        public bool $uploadSpike,
        public bool $telemetryNearCapacity,
    ) {
    }

    public function hasAlerts(): bool
    {
        return $this->unauthorizedSpike
            || $this->forbiddenSpike
            || $this->rateLimitedSpike
            || $this->cspSpike
            || $this->uploadSpike
            || $this->telemetryNearCapacity;
    }
}
