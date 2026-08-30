<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;

/** Builds a portable report cache; shared memory is always disposable. */
final readonly class AnalyticsReportCacheFactory
{
    private const string FILESYSTEM_NAMESPACE = 'analytics_reports_v1';

    private const string APCU_NAMESPACE_PREFIX = 'register_analytics_reports_';

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    public function create(string $cacheDirectory, string $applicationRoot, bool $disabled = false): AnalyticsReportCache
    {
        if ($disabled) {
            return new AnalyticsReportCache(new NullAdapter());
        }

        $filesystem = new FilesystemAdapter(
            self::FILESYSTEM_NAMESPACE,
            0,
            rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR,
        );
        if (!$this->apcuAvailable()) {
            return new AnalyticsReportCache($filesystem);
        }

        $root = realpath($applicationRoot);
        $root = $root === false ? rtrim($applicationRoot, '/\\') : $root;

        $namespace = self::APCU_NAMESPACE_PREFIX . substr(hash('sha256', $root), 0, 16);
        try {
            return new AnalyticsReportCache(new ChainAdapter([
                new ApcuAdapter($namespace, 0, 'v1'),
                $filesystem,
            ]));
        } catch (\Throwable $throwable) {
            $this->logger?->warning('Unable to enable APCu analytics reports; using filesystem only.', [
                'exception' => $throwable,
            ]);
            return new AnalyticsReportCache($filesystem);
        }
    }

    private function apcuAvailable(): bool
    {
        return ApcuAdapter::isSupported()
            && class_exists('APCUIterator', false)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL));
    }
}
