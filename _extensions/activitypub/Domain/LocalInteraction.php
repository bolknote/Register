<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

final readonly class LocalInteraction
{
    public function __construct(
        public int     $id,
        public int     $localActorId,
        public int     $remoteActorId,
        public string  $remoteObjectUrl,
        public string  $type,
        public string  $emoji,
        public string  $state,
        public int     $localActivityId,
        public ?int    $undoActivityId,
        public int     $createdAt,
        public int     $updatedAt,
        public ?int    $endedAt,
    ) {
        if ($id < 1
            || $localActorId < 1
            || $remoteActorId < 1
            || !str_starts_with($remoteObjectUrl, 'https://')
            || !\in_array($type, ['like', 'emoji_react', 'announce'], true)
            || mb_strlen($emoji) > 64
            || ($type === 'emoji_react' && $emoji === '')
            || ($type !== 'emoji_react' && $emoji !== '')
            || !\in_array($state, ['active', 'ended'], true)
            || $localActivityId < 1
            || ($undoActivityId !== null && $undoActivityId < 1)
            || $createdAt < 1
            || $updatedAt < 1
            || ($endedAt !== null && $endedAt < 1)
        ) {
            throw new \InvalidArgumentException('A stored local ActivityPub interaction is invalid.');
        }
    }
}
