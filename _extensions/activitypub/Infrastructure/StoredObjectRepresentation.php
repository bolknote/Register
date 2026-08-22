<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use Register\Content\ContentId;

final readonly class StoredObjectRepresentation
{
    public function __construct(
        public int       $id,
        public string    $publicId,
        public ContentId $contentId,
        public int       $incarnation,
        public int       $ownerActorId,
        public string    $objectType,
        public string    $visibility,
        public string    $state,
        public string    $canonicalUrl,
        public string    $snapshotJson,
        public string    $snapshotHash,
        public int       $publishedAt,
        public int       $updatedAt,
        public ?int      $deletedAt,
        public ?int      $featuredAt,
        public ?int      $broadcastAt,
    ) {
    }
}
