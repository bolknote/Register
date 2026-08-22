<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class RemoteObject
{
    public function __construct(
        public int     $id,
        public string  $objectUrl,
        public int     $ownerActorId,
        public string  $objectType,
        public ?string $inReplyToUrl,
        public string  $visibility,
        public string  $state,
        public int     $currentSnapshotId,
        public ?int    $publishedAt,
        public ?int    $remoteUpdatedAt,
        public ?int    $deletedAt,
        public int     $fetchedAt,
        public ?int    $featuredAt = null,
    ) {
        if ($id < 1
            || !str_starts_with($objectUrl, 'https://')
            || $ownerActorId < 1
            || !\in_array($objectType, ['Note', 'Article', 'Page'], true)
            || ($inReplyToUrl !== null && !str_starts_with($inReplyToUrl, 'https://'))
            || !\in_array($visibility, ['public', 'unlisted', 'followers', 'direct'], true)
            || !\in_array($state, ['live', 'deleted'], true)
            || $currentSnapshotId < 1
            || ($publishedAt !== null && $publishedAt < 0)
            || ($remoteUpdatedAt !== null && $remoteUpdatedAt < 0)
            || ($deletedAt !== null && $deletedAt < 0)
            || $fetchedAt < 1
            || ($featuredAt !== null && $featuredAt < 1)
        ) {
            throw new \InvalidArgumentException('A cached remote ActivityPub object is invalid.');
        }
    }
}
