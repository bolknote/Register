<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class StoredLocalNoteRepresentation
{
    public function __construct(
        public int     $id,
        public string  $publicId,
        public int     $actorId,
        public string  $inReplyToUrl,
        public int     $remoteActorId,
        public string  $visibility,
        public string  $state,
        public string  $snapshotJson,
        public string  $snapshotHash,
        public int     $publishedAt,
        public int     $updatedAt,
        public ?int    $deletedAt,
    ) {
        if ($id < 1
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $actorId < 1
            || !str_starts_with($inReplyToUrl, 'https://')
            || $remoteActorId < 1
            || !\in_array($visibility, ['public', 'unlisted', 'direct'], true)
            || !\in_array($state, ['live', 'tombstoned'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || $publishedAt < 1
            || $updatedAt < $publishedAt
            || ($deletedAt !== null && $deletedAt < 1)
        ) {
            throw new \InvalidArgumentException('A stored local ActivityPub Note is invalid.');
        }
    }
}
