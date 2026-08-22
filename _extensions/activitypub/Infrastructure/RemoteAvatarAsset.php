<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class RemoteAvatarAsset
{
    public function __construct(
        public string $publicId,
        public string $storageKey,
        public string $contentType,
        public string $contentHash,
        public int    $byteSize,
        public int    $width,
        public int    $height,
        public int    $fetchedAt,
        public int    $serveUntil,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $storageKey === ''
            || !\in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
            || $byteSize < 1
            || $width < 1
            || $height < 1
            || $fetchedAt < 1
            || $serveUntil <= $fetchedAt
        ) {
            throw new \InvalidArgumentException('A public remote avatar asset is invalid.');
        }
    }
}
