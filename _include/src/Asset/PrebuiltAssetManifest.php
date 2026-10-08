<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

/** Content hashes of the assets optimized by the release builder, kept outside the public surface. */
final readonly class PrebuiltAssetManifest
{
    public const string FILENAME = '_include/asset-manifest.json';

    /** @param array<string, string> $assets */
    private function __construct(
        private string $publicRoot,
        private string $basePath,
        private array  $assets,
    ) {
    }

    public static function fromPublicRoot(string $publicRoot, string $basePath): ?self
    {
        $manifest = rtrim($publicRoot, '/\\') . '/' . self::FILENAME;
        if (!is_file($manifest)) {
            return null;
        }

        $root = realpath($publicRoot);
        $json = file_get_contents($manifest);
        if ($root === false || !\is_string($json)) {
            throw new \RuntimeException('Unable to read the prebuilt asset manifest.');
        }
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!\is_array($data) || ($data['version'] ?? null) !== 1 || !\is_array($data['assets'] ?? null)) {
            throw new \RuntimeException('The prebuilt asset manifest has an unsupported format.');
        }

        $assets = [];
        foreach ($data['assets'] as $path => $hash) {
            if (!\is_string($path) || !self::isAssetPath($path)
                || !\is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            ) {
                throw new \RuntimeException('The prebuilt asset manifest contains an invalid entry.');
            }
            $assets[$path] = $hash;
        }

        return new self(rtrim($root, '/\\') . DIRECTORY_SEPARATOR, rtrim($basePath, '/'), $assets);
    }

    public function urlFor(string $filename): ?string
    {
        $resolved = realpath($filename);
        if ($resolved === false || !str_starts_with($resolved, $this->publicRoot)) {
            return null;
        }

        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, \strlen($this->publicRoot)));
        $hash = $this->assets[$path] ?? null;

        // The virtual suffix makes nginx-to-Apache hosting negotiate ready sidecars too.
        return $hash === null ? null : $this->basePath . '/' . $path . '.asset?v=' . $hash;
    }

    private static function isAssetPath(string $path): bool
    {
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            || preg_match('~^(?:(?:_admin|_assets|_extensions|_styles)/.+\.(?:css|m?js)|service-worker\.js)$~D', $path) !== 1
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }
}
