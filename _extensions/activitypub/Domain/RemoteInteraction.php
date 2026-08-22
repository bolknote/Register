<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

final readonly class RemoteInteraction
{
    /** @param array<string, mixed> $provenance */
    public function __construct(
        public int     $id,
        public string  $type,
        public int     $remoteActorId,
        public string  $remoteActivityUrl,
        public ?string $remoteObjectUrl,
        public ?int    $localObjectId,
        public ?int    $localCommentId,
        public string  $reactionSourceKey,
        public string  $emoji,
        public bool    $isPublic,
        public string  $state,
        public array   $provenance,
        public int     $createdAt,
        public int     $updatedAt,
        public ?int    $endedAt,
        public ?int    $localNoteId,
    ) {
        if ($id < 1
            || !\in_array($type, ['reply', 'direct_note', 'like', 'emoji_react', 'announce', 'flag'], true)
            || $remoteActorId < 1
            || !str_starts_with($remoteActivityUrl, 'https://')
            || ($remoteObjectUrl !== null && !str_starts_with($remoteObjectUrl, 'https://'))
            || ($localObjectId !== null && $localObjectId < 1)
            || ($localNoteId !== null && $localNoteId < 1)
            || ($localObjectId !== null && $localNoteId !== null)
            || ($localCommentId !== null && $localCommentId < 1)
            || \strlen($reactionSourceKey) > 128
            || mb_strlen($emoji) > 64
            || !\in_array($state, ['active', 'undone', 'deleted', 'rejected'], true)
            || $createdAt < 1
            || $updatedAt < 1
            || ($endedAt !== null && $endedAt < 1)
        ) {
            throw new \InvalidArgumentException('A stored remote ActivityPub interaction is invalid.');
        }
    }
}
