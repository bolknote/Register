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

    /**
     * @param array<string, string> $assets
     * @param list<array{path: string, files: list<string>}> $bundles
     */
    private function __construct(
        private string $publicRoot,
        private string $basePath,
        private array  $assets,
        private array  $bundles,
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

        $bundles = self::readBundles($data['bundles'] ?? [], $assets);

        return new self(rtrim($root, '/\\') . DIRECTORY_SEPARATOR, rtrim($basePath, '/'), $assets, $bundles);
    }

    public function urlFor(string $filename): ?string
    {
        $resolved = realpath($filename);
        if ($resolved === false) {
            return null;
        }

        if (!str_starts_with($resolved, $this->publicRoot)) {
            return null;
        }

        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, \strlen($this->publicRoot)));
        $hash = $this->assets[$path] ?? null;

        // The virtual suffix makes nginx-to-Apache hosting negotiate ready sidecars too.
        return $hash === null ? null : $this->publicUrl($path);
    }

    /**
     * Replace only exact consecutive groups, retaining order and every optional module boundary.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public function bundleUrls(array $urls): array
    {
        $result = [];
        for ($index = 0, $total = \count($urls); $index < $total;) {
            foreach ($this->bundles as $bundle) {
                $expected = array_map($this->publicUrl(...), $bundle['files']);
                if (\array_slice($urls, $index, \count($expected)) === $expected) {
                    $result[] = $this->publicUrl($bundle['path']);
                    $index += \count($expected);
                    continue 2;
                }
            }

            $result[] = $urls[$index];
            ++$index;
        }

        return $result;
    }

    private function publicUrl(string $path): string
    {
        return $this->basePath . '/' . $path . '.asset?v=' . $this->assets[$path];
    }

    /**
     * @param array<string, string> $assets
     * @return list<array{path: string, files: list<string>}>
     */
    private static function readBundles(mixed $definitions, array $assets): array
    {
        if (!\is_array($definitions) || !array_is_list($definitions)) {
            throw new \RuntimeException('The prebuilt asset manifest contains invalid bundles.');
        }

        $bundles = [];
        foreach ($definitions as $definition) {
            if (!\is_array($definition) || !\is_string($definition['path'] ?? null)
                || !isset($assets[$definition['path']]) || !\is_array($definition['files'] ?? null)
                || !array_is_list($definition['files']) || \count($definition['files']) < 2
            ) {
                throw new \RuntimeException('The prebuilt asset manifest contains an invalid bundle.');
            }

            $extension = pathinfo($definition['path'], PATHINFO_EXTENSION);
            if (!\in_array($extension, ['css', 'js'], true)) {
                throw new \RuntimeException('Prebuilt bundles support CSS and classic JavaScript only.');
            }

            $files = [];
            foreach ($definition['files'] as $file) {
                if (!\is_string($file) || !isset($assets[$file]) || $file === $definition['path']
                    || \in_array($file, $files, true) || pathinfo($file, PATHINFO_EXTENSION) !== $extension
                ) {
                    throw new \RuntimeException('The prebuilt asset manifest contains an invalid bundle member.');
                }

                $files[] = $file;
            }

            $bundles[] = ['path' => $definition['path'], 'files' => $files];
        }

        // Prefer a complete group over a shorter variant sharing its prefix.
        usort($bundles, static fn(array $left, array $right): int => \count($right['files']) <=> \count($left['files']));

        return $bundles;
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
