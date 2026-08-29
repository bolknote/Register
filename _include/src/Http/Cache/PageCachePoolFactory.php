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
    /**
     * Bump only when cached PHP values or anonymous HTML representations become incompatible.
     * Ordinary releases and render-neutral code changes must keep the existing cache warm.
     */
    public const int CACHE_ABI = 1;

    private const string FILESYSTEM_NAMESPACE = 'pages';

    private const string SHARED_MEMORY_NAMESPACE_PREFIX = 'register_pages_';

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    public function create(string $cacheDirectory, string $applicationRoot): PageCachePools
    {
        $cacheDirectory = rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $filesystemNamespace = self::filesystemNamespace();
        $filesystem = new FilesystemAdapter($filesystemNamespace, 0, $cacheDirectory);
        $filesystemDirectory = $cacheDirectory . $filesystemNamespace;

        if (!$this->apcuAvailable()) {
            return new PageCachePools($filesystem, $filesystem, $filesystemDirectory, false, null);
        }

        $root = realpath($applicationRoot);
        $root = $root === false ? rtrim($applicationRoot, '/\\') : $root;

        $namespace = self::SHARED_MEMORY_NAMESPACE_PREFIX . substr(hash('sha256', $root), 0, 16);
        $versionKey = 'abi' . self::CACHE_ABI;

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

    public static function filesystemNamespace(): string
    {
        return self::namespaceForAbi(self::CACHE_ABI);
    }

    public static function namespaceForAbi(int $abi): string
    {
        if ($abi < 1) {
            throw new \InvalidArgumentException('The page-cache ABI must be positive.');
        }

        return $abi === 1
            ? self::FILESYSTEM_NAMESPACE
            : self::FILESYSTEM_NAMESPACE . '_v' . $abi;
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
