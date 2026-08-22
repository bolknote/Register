<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

final readonly class IdentityHealthReport
{
    /** @param list<string> $errors */
    public function __construct(
        public int    $actorCount,
        public int    $keyCount,
        public string $identityFingerprint,
        public array  $errors,
    ) {
        if ($actorCount < 0
            || $keyCount < 0
            || preg_match('/^[a-f0-9]{64}$/D', $identityFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub identity health report is invalid.');
        }
    }

    public function isHealthy(): bool
    {
        return $this->errors === [];
    }
}
