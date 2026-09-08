<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

/** Builds cache-safe URLs for files served directly from the public application root. */
final readonly class PublicAssetUrl
{
    private string $publicRoot;

    private string $basePath;

    public function __construct(string $publicRoot, string $basePath)
    {
        $this->publicRoot = rtrim($publicRoot, '/\\') . DIRECTORY_SEPARATOR;
        $this->basePath   = rtrim($basePath, '/');
    }

    public function versioned(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || preg_match('~[\x00-\x1f\x7f]~', $path) === 1
        ) {
            throw new \InvalidArgumentException(\sprintf(
                'Public asset path "%s" must be a safe root-relative path.',
                $path,
            ));
        }

        $filename = $this->publicRoot . ltrim($path, '/');
        if (!is_file($filename)) {
            throw new \LogicException(\sprintf('The public asset "%s" does not exist.', $filename));
        }

        $modifiedAt = filemtime($filename);
        if ($modifiedAt === false) {
            throw new \LogicException(\sprintf('Unable to read the modification time of "%s".', $filename));
        }

        return $this->basePath . $path . '?v=' . $modifiedAt;
    }
}
