<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class NewStoredLocalNote
{
    public function __construct(
        public string $publicId,
        public int    $actorId,
        public string $inReplyToUrl,
        public int    $remoteActorId,
        public string $visibility,
        public string $snapshotJson,
        public string $snapshotHash,
        public int    $publishedAt,
        public int    $updatedAt,
        public int    $createdAt,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $actorId < 1
            || !str_starts_with($inReplyToUrl, 'https://')
            || \strlen($inReplyToUrl) > 2_048
            || $remoteActorId < 1
            || !\in_array($visibility, ['public', 'unlisted', 'direct'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || $publishedAt < 1
            || $updatedAt < $publishedAt
            || $createdAt < 1
        ) {
            throw new \InvalidArgumentException('A new local ActivityPub Note is invalid.');
        }
    }
}
