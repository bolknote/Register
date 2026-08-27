<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/** Builds a portable filesystem page cache with an optional APCu tier for bounded hot data. */
final readonly class PageCachePoolFactory
{
    private const string FILESYSTEM_NAMESPACE = 'pages';

    private const string SHARED_MEMORY_NAMESPACE_PREFIX = 'register_pages_';

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    public function create(string $cacheDirectory, string $applicationRoot, string $version): PageCachePools
    {
        $cacheDirectory = rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $filesystem = new FilesystemAdapter(self::FILESYSTEM_NAMESPACE, 0, $cacheDirectory);
        $filesystemDirectory = $cacheDirectory . self::FILESYSTEM_NAMESPACE;

        if (!$this->apcuAvailable()) {
            return new PageCachePools($filesystem, $filesystem, $filesystemDirectory, false, null);
        }

        $root = realpath($applicationRoot);
        $root = $root === false ? rtrim($applicationRoot, '/\\') : $root;

        $namespace = self::SHARED_MEMORY_NAMESPACE_PREFIX . substr(hash('sha256', $root), 0, 16);
        $versionKey = 'v' . substr(hash('sha256', $version), 0, 16);

        try {
            // APCUIterator is required by apcuAvailable(), so changing this version
            // clears only this site's namespace, never the shared host's whole segment.
            $sharedMemory = new ApcuAdapter($namespace, 0, $versionKey);
            $hot = new ChainAdapter([$sharedMemory, $filesystem]);
        } catch (\Throwable $throwable) {
            $this->logger?->warning('Unable to enable the APCu page-cache tier; using filesystem only.', [
                'exception' => $throwable,
            ]);

            return new PageCachePools($filesystem, $filesystem, $filesystemDirectory, false, null);
        }

        return new PageCachePools($filesystem, $hot, $filesystemDirectory, true, $namespace);
    }

    private function apcuAvailable(): bool
    {
        if (!ApcuAdapter::isSupported()) {
            return false;
        }

        if (!class_exists('APCUIterator', false)) {
            return false;
        }

        return PHP_SAPI !== 'cli'
            || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL);
    }
}
