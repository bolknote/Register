<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class FollowRelationship
{
    public function __construct(
        public int     $id,
        public string  $direction,
        public int     $localActorId,
        public int     $remoteActorId,
        public string  $state,
        public string  $followActivityUrl,
        public ?int    $localActivityId,
        public int     $createdAt,
        public int     $updatedAt,
        public ?int    $acceptedAt,
        public ?int    $endedAt,
    ) {
        if ($id < 1
            || !\in_array($direction, ['incoming', 'outgoing'], true)
            || $localActorId < 1
            || $remoteActorId < 1
            || !\in_array($state, ['pending', 'accepted', 'rejected', 'ended'], true)
            || !str_starts_with($followActivityUrl, 'https://')
            || ($localActivityId !== null && $localActivityId < 1)
            || $createdAt < 1
            || $updatedAt < 1
            || ($acceptedAt !== null && $acceptedAt < 1)
            || ($endedAt !== null && $endedAt < 1)
        ) {
            throw new \InvalidArgumentException('A stored ActivityPub follow relationship is invalid.');
        }
    }

    public function isCurrent(): bool
    {
        return \in_array($this->state, ['pending', 'accepted'], true);
    }
}
