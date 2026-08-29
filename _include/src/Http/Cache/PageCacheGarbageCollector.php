<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;

/** Gradually removes obsolete page-cache ABI generations without causing an I/O burst. */
final readonly class PageCacheGarbageCollector implements ScheduledMaintenanceTaskInterface
{
    private const int MAX_ENTRIES_PER_PASS = 128;

    private string $cacheDirectory;

    public function __construct(string $cacheDirectory, private string $currentNamespace)
    {
        if (!$this->isPageCacheNamespace($currentNamespace)) {
            throw new \InvalidArgumentException('The current page-cache namespace is invalid.');
        }

        $this->cacheDirectory = rtrim($cacheDirectory, '/\\');
        if ($this->cacheDirectory === '') {
            throw new \InvalidArgumentException('The page-cache directory must not be empty.');
        }
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The page-cache maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.05);
        $this->collect();
    }

    public function collect(int $limit = self::MAX_ENTRIES_PER_PASS): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page-cache cleanup limit must be positive.');
        }

        if (!is_dir($this->cacheDirectory) || is_link($this->cacheDirectory)) {
            return 0;
        }

        $entries = scandir($this->cacheDirectory);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect the page-cache directory.');
        }

        $removed = 0;
        foreach ($entries as $entry) {
            if ($entry === $this->currentNamespace || !$this->isPageCacheNamespace($entry)) {
                continue;
            }

            $directory = $this->cacheDirectory . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) {
                    continue;
                }

                $path = $item->getPathname();
                $deleted = $item->isDir() && !$item->isLink()
                    ? register_call_without_warnings(static fn(): bool => rmdir($path))
                    : register_call_without_warnings(static fn(): bool => unlink($path));
                if (!$deleted) {
                    throw new \RuntimeException('Unable to remove an obsolete page-cache entry.');
                }

                ++$removed;
                if ($removed >= $limit) {
                    return $removed;
                }
            }

            if (register_call_without_warnings(static fn(): bool => rmdir($directory))) {
                ++$removed;
                if ($removed >= $limit) {
                    return $removed;
                }
            }
        }

        return $removed;
    }

    private function isPageCacheNamespace(string $entry): bool
    {
        return preg_match('/^pages(?:_v[1-9][0-9]*)?$/D', $entry) === 1;
    }
}
