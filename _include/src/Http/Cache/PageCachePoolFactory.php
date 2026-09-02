<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/** Builds a durable page cache with encrypted APCu and tmpfs tiers when the host supports them. */
final readonly class PageCachePoolFactory
{
    /**
     * Bump only when cached PHP values or anonymous HTML representations become incompatible.
     * Ordinary releases and render-neutral code changes must keep the existing cache warm.
     */
    public const int CACHE_ABI = 4;

    private const string FILESYSTEM_NAMESPACE = 'pages';

    private const string SHARED_MEMORY_NAMESPACE_PREFIX = 'register_pages_';

    private VolatileCacheEnvironmentInterface $environment;

    public function __construct(
        private ?LoggerInterface $logger = null,
        ?VolatileCacheEnvironmentInterface $environment = null,
        private ?VolatileCacheEncryptionKeyProvider $keyProvider = null,
    ) {
        $this->environment = $environment ?? new NativeVolatileCacheEnvironment();
    }

    public function create(string $cacheDirectory, string $applicationRoot): PageCachePools
    {
        $cacheDirectory = rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $filesystemNamespace = self::filesystemNamespace();
        $filesystem = new FilesystemAdapter($filesystemNamespace, 0, $cacheDirectory);
        $filesystemDirectory = $cacheDirectory . $filesystemNamespace;

        try {
            $apcuAvailable = $this->environment->apcuAvailable();
            $tmpfsDirectory = $this->environment->tmpfsDirectory($applicationRoot);
        } catch (\Throwable $throwable) {
            $this->logger?->warning('Unable to inspect volatile page-cache backends; using filesystem only.', [
                'exception' => $throwable,
            ]);

            return $this->filesystemOnly($filesystem, $filesystemDirectory);
        }

        if ((!$apcuAvailable && $tmpfsDirectory === null) || $this->keyProvider === null) {
            return $this->filesystemOnly($filesystem, $filesystemDirectory);
        }

        try {
            $encryptionKey = $this->keyProvider->get();
            $marshaller = new EncryptedCacheMarshaller([$encryptionKey->key]);
        } catch (\Throwable $throwable) {
            $this->logger?->warning('Unable to load the volatile page-cache encryption key; using filesystem only.', [
                'exception' => $throwable,
            ]);

            return $this->filesystemOnly($filesystem, $filesystemDirectory);
        }

        $root = realpath($applicationRoot);
        $root = $root === false ? rtrim($applicationRoot, '/\\') : $root;

        $namespace = self::SHARED_MEMORY_NAMESPACE_PREFIX . substr(hash('sha256', $root), 0, 16);
        $versionKey = 'abi' . self::CACHE_ABI . '-' . $encryptionKey->fingerprint;
        $volatileAdapters = [];
        $apcuEnabled = false;
        $tmpfsCacheDirectory = null;

        if ($apcuAvailable) {
            try {
                // The version includes the key fingerprint: rotating the secret drops unreadable APCu entries.
                $volatileAdapters[] = new ResilientApcuAdapter(
                    $namespace,
                    0,
                    $versionKey,
                    $marshaller,
                    $this->logger,
                );
                $apcuEnabled = true;
            } catch (\Throwable $throwable) {
                $this->logger?->warning('Unable to enable the encrypted APCu page-cache tier.', [
                    'exception' => $throwable,
                ]);
            }
        }

        if ($tmpfsDirectory !== null) {
            try {
                $tmpfsNamespace = self::filesystemNamespace() . '_' . $encryptionKey->fingerprint;
                $tmpfsDirectory->prunePageCacheNamespaces($tmpfsNamespace);
                $volatileAdapters[] = new ResilientFilesystemAdapter(
                    $tmpfsNamespace,
                    0,
                    $tmpfsDirectory,
                    $marshaller,
                    $this->logger,
                );
                $tmpfsCacheDirectory = $tmpfsDirectory->path . DIRECTORY_SEPARATOR . $tmpfsNamespace;
            } catch (\Throwable $throwable) {
                $this->logger?->warning('Unable to enable the encrypted tmpfs page-cache tier.', [
                    'exception' => $throwable,
                ]);
            }
        }

        if ($volatileAdapters === []) {
            return $this->filesystemOnly($filesystem, $filesystemDirectory);
        }

        return new PageCachePools(
            $filesystem,
            new ChainAdapter([...$volatileAdapters, $filesystem]),
            $filesystemDirectory,
            $apcuEnabled,
            $apcuEnabled ? $namespace : null,
            $tmpfsCacheDirectory,
            true,
        );
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

    private function filesystemOnly(FilesystemAdapter $filesystem, string $filesystemDirectory): PageCachePools
    {
        return new PageCachePools($filesystem, $filesystem, $filesystemDirectory, false, null, null, false);
    }
}
