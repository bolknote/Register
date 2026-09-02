<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Core\Http\Cache\PageCachePools;

final readonly class DashboardPageCacheProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private PageCachePools    $pools,
        private bool              $enabled,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render('_admin/templates/dashboard/page-cache-item.php.inc', [
            'enabled'             => $this->enabled,
            'filesystemDirectory' => $this->pools->filesystemDirectory,
            'sharedMemoryEnabled' => $this->pools->sharedMemoryEnabled,
            'sharedMemoryInfo'    => $this->sharedMemoryInfo(),
            'tmpfsInfo'           => $this->tmpfsInfo(),
            'hotCacheDescription' => $this->hotCacheDescription(),
            'volatileEncryptionEnabled' => $this->pools->volatileEncryptionEnabled,
        ]);
    }

    private function hotCacheDescription(): string
    {
        return match (true) {
            $this->pools->sharedMemoryEnabled && $this->pools->tmpfsDirectory !== null
                => 'Encrypted APCu and tmpfs page cache enabled',
            $this->pools->sharedMemoryEnabled => 'Encrypted APCu page cache enabled',
            $this->pools->tmpfsDirectory !== null => 'Encrypted tmpfs page cache enabled',
            default => 'Volatile page cache unavailable',
        };
    }

    /**
     * @return array{
     *     total:int,
     *     available:int,
     *     entries:int,
     *     application:array{bytes:int, entries:int}|null
     * }|null
     */
    private function sharedMemoryInfo(): ?array
    {
        if (!$this->pools->sharedMemoryEnabled
            || !\function_exists('apcu_sma_info')
            || !\function_exists('apcu_cache_info')
        ) {
            return null;
        }

        $memory = register_call_without_warnings(static fn(): array|false => apcu_sma_info(true));
        $cache = register_call_without_warnings(static fn(): array|false => apcu_cache_info(true));
        if (!\is_array($memory) || !\is_array($cache)) {
            return null;
        }

        $segments = $memory['num_seg'] ?? null;
        $segmentSize = $memory['seg_size'] ?? null;
        $available = $memory['avail_mem'] ?? null;
        $entries = $cache['num_entries'] ?? null;
        if (!\is_int($segments)
            || !\is_float($segmentSize) && !\is_int($segmentSize)
            || !\is_float($available) && !\is_int($available)
            || !\is_int($entries)
        ) {
            return null;
        }

        $segmentBytes = (int)$segmentSize;

        return [
            'total'     => max(0, $segments * $segmentBytes),
            'available' => max(0, (int)$available),
            'entries'   => max(0, $entries),
            'application' => $this->applicationSharedMemoryInfo(),
        ];
    }

    /** @return array{bytes:int, entries:int}|null */
    private function applicationSharedMemoryInfo(): ?array
    {
        $iteratorClass = $this->apcuIteratorClass();
        if ($this->pools->sharedMemoryNamespace === null || $iteratorClass === null) {
            return null;
        }

        $bytes = 0;
        $entries = 0;
        $pattern = '/^' . preg_quote($this->pools->sharedMemoryNamespace . ':', '/') . '/';

        try {
            $iterator = (new \ReflectionClass($iteratorClass))->newInstance($pattern);
            if (!$iterator instanceof \Traversable) {
                return null;
            }

            foreach ($iterator as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $memorySize = $entry['mem_size'] ?? null;
                if (!\is_int($memorySize) && !\is_float($memorySize)) {
                    continue;
                }

                $bytes += max(0, (int)$memorySize);
                ++$entries;
            }
        } catch (\Throwable) {
            return null;
        }

        return ['bytes' => $bytes, 'entries' => $entries];
    }

    /** @return array{total:int, available:int, bytes:int, entries:int}|null */
    private function tmpfsInfo(): ?array
    {
        $directory = $this->pools->tmpfsDirectory;
        if ($directory === null || !is_dir($directory) || is_link($directory)) {
            return null;
        }

        $total     = register_call_without_warnings(static fn(): float|false => disk_total_space($directory));
        $available = register_call_without_warnings(static fn(): float|false => disk_free_space($directory));
        if ($total === false || $available === false) {
            return null;
        }

        $bytes   = 0;
        $entries = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }

                $bytes += max(0, $file->getSize());
                ++$entries;
            }
        } catch (\Throwable) {
            return null;
        }

        return [
            'total'     => max(0, (int)$total),
            'available' => max(0, (int)$available),
            'bytes'     => $bytes,
            'entries'   => $entries,
        ];
    }

    /** @return class-string|null */
    private function apcuIteratorClass(): ?string
    {
        foreach (get_declared_classes() as $class) {
            if (strcasecmp($class, 'APCUIterator') === 0) {
                return $class;
            }
        }

        return null;
    }
}
