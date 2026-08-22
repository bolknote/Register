<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class NewRemoteInteraction
{
    /** @param array<string, mixed> $provenance */
    public function __construct(
        public string  $type,
        public int     $remoteActorId,
        public string  $remoteActivityUrl,
        public ?string $remoteObjectUrl,
        public ?int    $localObjectId,
        public ?int    $localCommentId,
        public string  $reactionSourceKey,
        public string  $emoji,
        public array   $provenance,
        public int     $createdAt,
        public ?int    $localNoteId = null,
    ) {
        if (!\in_array($type, ['reply', 'direct_note', 'like', 'emoji_react', 'announce', 'flag'], true)
            || $remoteActorId < 1
            || !str_starts_with($remoteActivityUrl, 'https://')
            || ($remoteObjectUrl !== null && !str_starts_with($remoteObjectUrl, 'https://'))
            || ($localObjectId !== null && $localObjectId < 1)
            || ($localNoteId !== null && $localNoteId < 1)
            || ($localObjectId !== null && $localNoteId !== null)
            || ($localCommentId !== null && $localCommentId < 1)
            || \strlen($reactionSourceKey) > 128
            || mb_strlen($emoji) > 64
            || $createdAt < 1
        ) {
            throw new \InvalidArgumentException('A new remote ActivityPub interaction is invalid.');
        }
    }
}
