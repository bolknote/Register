<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class ClaimedDelivery
{
    /**
     * @param list<string> $recipients
     * @param list<string> $redirectChain
     */
    public function __construct(
        public int    $id,
        public int    $activityId,
        public int    $actorId,
        public string $activityBody,
        public string $activityBodyHash,
        public string $inboxUrl,
        public string $requestUrl,
        public string $effectiveOrigin,
        public array         $recipients,
        public int    $attemptCount,
        public int    $authRefreshCount,
        public int    $redirectCount,
        public array         $redirectChain,
        public int    $expiresAt,
        public string $claimToken,
    ) {
        if ($id < 1
            || $activityId < 1
            || $actorId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $activityBodyHash) !== 1
            || !str_starts_with($inboxUrl, 'https://')
            || !str_starts_with($requestUrl, 'https://')
            || !str_starts_with($effectiveOrigin, 'https://')
            || $attemptCount < 1
            || $authRefreshCount < 0
            || $redirectCount < 0
            || $expiresAt < 1
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $claimToken) !== 1
        ) {
            throw new \InvalidArgumentException('A claimed ActivityPub delivery is invalid.');
        }
    }
}
