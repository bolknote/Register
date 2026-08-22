<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Ai\Admin;

use Register\Ai\AiException;
use Register\Ai\AiImageInput;

/** Resolves an editor media URL to a file inside the configured upload directory. */
final readonly class AiImageLoader
{
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private string $imageDirectory,
        private string $imageUrl,
    ) {
    }

    /** @throws AiException */
    public function load(string $source): AiImageInput
    {
        $relativePath = $this->relativePath($source);
        $root = realpath($this->imageDirectory);
        if ($root === false || !is_dir($root)) {
            throw new AiException('The image storage is unavailable.');
        }

        $filename = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($filename === false || !str_starts_with($filename, $rootPrefix) || !is_file($filename)) {
            throw new AiException('The image is unavailable.');
        }

        $size = filesize($filename);
        if (!\is_int($size) || $size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new AiException('The image is empty or too large.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($filename);
        if (!\is_string($mimeType) || !str_starts_with($mimeType, 'image/')) {
            throw new AiException('The selected file is not an image.');
        }

        $data = file_get_contents($filename);
        if (!\is_string($data) || $data === '') {
            throw new AiException('The image could not be read.');
        }

        return new AiImageInput(strtolower($mimeType), $data);
    }

    /** @throws AiException */
    private function relativePath(string $source): string
    {
        $source = html_entity_decode(trim($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($source === '' || strlen($source) > 4096 || preg_match('/[\x00-\x1f\x7f\\\\]/', $source) === 1) {
            throw new AiException('The image URL is invalid.');
        }

        $sourceParts = parse_url($source);
        $baseParts = parse_url($this->imageUrl);
        if (!\is_array($sourceParts) || !\is_array($baseParts) || isset($sourceParts['query']) || isset($sourceParts['fragment'])) {
            throw new AiException('The image URL is invalid.');
        }

        $baseHasOrigin = isset($baseParts['scheme']) || isset($baseParts['host']);
        if ($baseHasOrigin) {
            if (strtolower($sourceParts['scheme'] ?? '') !== strtolower($baseParts['scheme'] ?? '')
                || strtolower($sourceParts['host'] ?? '') !== strtolower($baseParts['host'] ?? '')
                || ($sourceParts['port'] ?? null) !== ($baseParts['port'] ?? null)
            ) {
                throw new AiException('The image URL is outside the media storage.');
            }
        } elseif (isset($sourceParts['scheme']) || isset($sourceParts['host'])) {
            throw new AiException('The image URL is outside the media storage.');
        }

        $sourcePath = $sourceParts['path'] ?? '';
        $basePath = rtrim($baseParts['path'] ?? '', '/');
        if ($basePath === '' || !str_starts_with($sourcePath, $basePath . '/')) {
            throw new AiException('The image URL is outside the media storage.');
        }

        $relativePath = rawurldecode(substr($sourcePath, strlen($basePath) + 1));
        if ($relativePath === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $relativePath) === 1) {
            throw new AiException('The image URL is invalid.');
        }

        $segments = explode('/', $relativePath);
        if (\in_array('', $segments, true) || \in_array('.', $segments, true) || \in_array('..', $segments, true)) {
            throw new AiException('The image URL is invalid.');
        }

        return implode('/', $segments);
    }
}
