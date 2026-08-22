<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

final readonly class InspectedRemoteAvatar
{
    public function __construct(
        public string $contentType,
        public string $extension,
        public string $contentHash,
        public int    $byteSize,
        public int    $width,
        public int    $height,
    ) {
        if (!\in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || !\in_array($extension, ['jpg', 'png', 'webp'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
            || $byteSize < 1
            || $width < 1
            || $height < 1
        ) {
            throw new \InvalidArgumentException('Inspected remote avatar metadata is invalid.');
        }
    }
}
