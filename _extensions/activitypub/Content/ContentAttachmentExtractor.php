<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Content;

use Register\Core\HttpClient\HttpClientException;
use Register\Core\HttpClient\HttpClientInterface;

/** Extracts bounded metadata for locally stored images without network I/O or path traversal. */
final readonly class ContentAttachmentExtractor
{
    private const int MAX_ATTACHMENTS = 16;

    private const int MAX_FILE_BYTES = 100 * 1_024 * 1_024;

    private const array ALLOWED_MEDIA_TYPES = [
        'image/avif',
        'image/bmp',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private HttpClientInterface $urlResolver,
        private string              $imageDirectory,
        private string              $imagePath,
    ) {
        if (trim($this->imageDirectory) === '' || trim($this->imagePath) === '') {
            throw new \InvalidArgumentException('ActivityPub attachment storage paths must not be empty.');
        }
    }

    /** @return list<array<string, int|string>> */
    public function extract(string $html, string $baseUrl): array
    {
        if ($html === '' || !mb_check_encoding($html, 'UTF-8')) {
            return [];
        }

        $storageRoot = realpath($this->imageDirectory);
        if (!\is_string($storageRoot) || !is_dir($storageRoot)) {
            return [];
        }

        $publicPrefix = $this->publicPrefix($baseUrl);
        if ($publicPrefix === null) {
            return [];
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return [];
        }

        $attachments = [];
        $seen = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            if (\count($attachments) >= self::MAX_ATTACHMENTS) {
                break;
            }

            $url = $this->absoluteUrl($image->getAttribute('src'), $baseUrl);
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $file = $this->localFile($url, $publicPrefix, $storageRoot);
            if ($file === null) {
                continue;
            }

            $size = filesize($file);
            if (!\is_int($size) || $size < 1 || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $imageInfo = \register_call_without_warnings(static fn(): array|false => getimagesize($file));
            if (!\is_array($imageInfo)) {
                continue;
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mediaType = $imageInfo['mime'];
            if ($width < 1 || $height < 1
                || !\in_array(strtolower($mediaType), self::ALLOWED_MEDIA_TYPES, true)
            ) {
                continue;
            }

            $alt = $this->plainText($image->getAttribute('alt'), 2_000);
            $attachments[] = [
                'type'      => 'Document',
                'mediaType' => strtolower($mediaType),
                'url'       => $url,
                'name'      => $alt,
                'width'     => $width,
                'height'    => $height,
                'size'      => $size,
            ];
            $seen[$url] = true;
        }

        return $attachments;
    }

    private function publicPrefix(string $baseUrl): ?string
    {
        try {
            $prefix = str_starts_with($this->imagePath, 'https://')
                ? $this->imagePath
                : $this->urlResolver->resolveRedirectUrl($this->imagePath . '/', $baseUrl);
        } catch (HttpClientException | \InvalidArgumentException) {
            return null;
        }

        $parts = parse_url($prefix);
        if (!\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return rtrim($prefix, '/');
    }

    private function absoluteUrl(string $value, string $baseUrl): ?string
    {
        if ($value === ''
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || str_contains($value, '\\')
        ) {
            return null;
        }

        try {
            $url = $this->urlResolver->resolveRedirectUrl($value, $baseUrl);
        } catch (HttpClientException | \InvalidArgumentException) {
            return null;
        }

        $parts = parse_url($url);
        if (!\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return $url;
    }

    private function localFile(string $url, string $publicPrefix, string $storageRoot): ?string
    {
        if (!str_starts_with($url, $publicPrefix . '/')) {
            return null;
        }

        $relative = substr($url, \strlen($publicPrefix) + 1);
        $encodedSegments = explode('/', $relative);
        $segments = [];
        foreach ($encodedSegments as $encodedSegment) {
            $segment = rawurldecode($encodedSegment);
            if (in_array($segment, ['', '.', '..'], true)
                || str_contains($segment, '/')
                || str_contains($segment, '\\')
                || preg_match('/[\x00-\x1f\x7f]/', $segment) === 1
            ) {
                return null;
            }

            $segments[] = $segment;
        }

        $candidate = $storageRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $realFile = realpath($candidate);
        if ($realFile === false) {
            return null;
        }

        if (!str_starts_with($realFile, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_file($realFile)
        ) {
            return null;
        }

        return $realFile;
    }

    private function plainText(string $value, int $maxCharacters): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($value, 0, $maxCharacters);
    }
}
