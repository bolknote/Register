<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

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
        ]);
    }

    /** @return array{total:int, available:int, entries:int}|null */
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
        ];
    }
}
