<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class GeneratedAssetCacheCleaner
{
    /** Runtime evidence and expensive content-derived caches survive code switches. */
    private const array PRESERVED_ENTRIES = [
        '.htaccess',
        'index.html',
        'recommendations',
        'picture_reserve',
        'query-profiler-state.json',
    ];

    private string $cacheDirectory;

    public function __construct(string $publicRoot)
    {
        $this->cacheDirectory = rtrim($publicRoot, '/\\') . '/_cache';
    }

    public function clear(): void
    {
        if (!is_dir($this->cacheDirectory) || is_link($this->cacheDirectory)) {
            throw new \RuntimeException('The public generated-asset cache directory is missing or unsafe.');
        }

        $entries = scandir($this->cacheDirectory);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect the public generated-asset cache.');
        }

        foreach ($entries as $entry) {
            if ($this->isPreservedEntry($entry)) {
                continue;
            }

            $path = $this->cacheDirectory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } elseif ((is_file($path) || is_link($path)) && !unlink($path)) {
                throw new \RuntimeException('Unable to remove a stale generated asset: ' . $entry);
            }
        }
    }

    private function isPreservedEntry(string $entry): bool
    {
        if (\in_array($entry, ['.', '..', ...self::PRESERVED_ENTRIES], true)) {
            return true;
        }

        // Monitoring, security and diagnostic logs are operational data, not
        // generated assets. Deployments must not erase the evidence needed to
        // investigate the load that prompted the deployment.
        return str_ends_with($entry, '.log')
            || str_ends_with($entry, '.jsonl')
            || str_ends_with($entry, '.lock');
    }

    private function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $removed = $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
            if (!$removed) {
                throw new \RuntimeException('Unable to remove a stale generated-asset cache entry.');
            }
        }

        if (!rmdir($directory)) {
            throw new \RuntimeException('Unable to remove a stale generated-asset cache directory.');
        }
    }
}
