<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use Register\Content\ContentId;

final readonly class NewStoredObject
{
    public function __construct(
        public string    $publicId,
        public ContentId $contentId,
        public int       $incarnation,
        public int       $ownerActorId,
        public string    $objectType,
        public string    $visibility,
        public string    $canonicalUrl,
        public string    $snapshotJson,
        public string    $snapshotHash,
        public int       $publishedAt,
        public int       $updatedAt,
        public int       $createdAt,
        public ?int      $broadcastAt = null,
        public ?int      $featuredAt = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $incarnation < 1
            || $ownerActorId < 1
            || !\in_array($objectType, ['Article', 'Note', 'Page'], true)
            || !\in_array($visibility, ['public', 'unlisted'], true)
            || !str_starts_with($canonicalUrl, 'https://')
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || $publishedAt < 1
            || $updatedAt < 1
            || $createdAt < 1
            || ($broadcastAt !== null && $broadcastAt < 1)
            || ($featuredAt !== null && $featuredAt < 1)
        ) {
            throw new \InvalidArgumentException('The new local ActivityPub object record is invalid.');
        }
    }
}
