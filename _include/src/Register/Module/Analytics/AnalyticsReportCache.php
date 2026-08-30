<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Small typed facade around the APCu-to-filesystem report cache. */
final readonly class AnalyticsReportCache
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function remember(string $key, int $ttl, \Closure $loader): mixed
    {
        if ($ttl < 1 || preg_match('/^[a-zA-Z0-9_.-]{1,180}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics report cache coordinate.');
        }

        return $this->cache->get($key, static function (ItemInterface $item) use ($ttl, $loader): mixed {
            $item->expiresAfter($ttl);
            return $loader();
        });
    }
}
