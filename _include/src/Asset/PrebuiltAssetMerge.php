<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

/** Serve build-time optimized files without passing modern JS/CSS through the legacy PHP minifier. */
final class PrebuiltAssetMerge implements AssetMergeInterface
{
    /** @var list<string> */
    private array $files = [];

    public function __construct(
        private readonly PrebuiltAssetManifest $manifest,
        private readonly AssetMergeInterface   $fallback,
    ) {
    }

    #[\Override]
    public function concat(string $fileName): void
    {
        $this->files[] = $fileName;
        $this->fallback->concat($fileName);
    }

    /**
     * @return string[]
     */
    #[\Override]
    public function getMergedPaths(): array
    {
        $paths = [];
        foreach ($this->files as $filename) {
            $path = $this->manifest->urlFor($filename);
            if ($path === null) {
                // User-installed themes, extensions and remote assets keep the existing runtime workflow.
                return $this->fallback->getMergedPaths();
            }

            $paths[] = $path;
        }

        return $this->bundleUrls($paths);
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    public function bundleUrls(array $urls): array
    {
        return $this->manifest->bundleUrls($urls);
    }
}
