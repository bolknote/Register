<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

/** Validates actual image bytes; SVG and formats with inconsistent shared-hosting support are rejected. */
final readonly class RemoteAvatarImageInspector
{
    public const int MAX_BYTES = 512 * 1024;

    private const int MAX_EDGE = 4_096;

    private const int MAX_PIXELS = 4_194_304;

    public function inspect(string $content, ?string $declaredContentType): InspectedRemoteAvatar
    {
        $byteSize = \strlen($content);
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
            throw new \DomainException('A remote avatar exceeds the 512 KiB byte limit.');
        }

        $warning = null;
        $info = s2_call_without_warnings(
            static fn(): array|false => getimagesizefromstring($content),
            $warning,
        );
        unset($warning);
        if (!\is_array($info)) {
            throw new \DomainException('A remote avatar body is not a decodable raster image.');
        }

        $width  = $info[0];
        $height = $info[1];
        $type   = $info[2];
        [$contentType, $extension] = match ($type) {
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG  => ['image/png', 'png'],
            IMAGETYPE_WEBP => ['image/webp', 'webp'],
            default        => throw new \DomainException('A remote avatar format is not allowed.'),
        };
        if ($width < 1
            || $height < 1
            || $width > self::MAX_EDGE
            || $height > self::MAX_EDGE
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new \DomainException('A remote avatar exceeds the geometry limit.');
        }

        $declared = $this->normalizeDeclaredContentType($declaredContentType);
        if ($declared !== null
            && $declared !== 'application/octet-stream'
            && !hash_equals($contentType, $declared)
        ) {
            throw new \DomainException('A remote avatar Content-Type does not match its bytes.');
        }

        return new InspectedRemoteAvatar(
            $contentType,
            $extension,
            hash('sha256', $content),
            $byteSize,
            $width,
            $height,
        );
    }

    private function normalizeDeclaredContentType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (\strlen($value) > 255 || str_contains($value, ',') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \DomainException('A remote avatar Content-Type header is invalid.');
        }

        $mediaType = strtolower(trim(explode(';', $value, 2)[0]));
        if ($mediaType === '') {
            throw new \DomainException('A remote avatar Content-Type header is empty.');
        }

        return $mediaType;
    }
}
