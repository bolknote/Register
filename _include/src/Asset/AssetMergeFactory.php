<?php
/**
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

use Psr\Log\LoggerInterface;
use Register\Core\HttpClient\HttpClient;

readonly class AssetMergeFactory
{
    public function __construct(
        private HttpClient $httpClient,
        private LoggerInterface $logger,
        private bool       $debug,
        private string     $publicCacheDir,
        private string     $publicCachePath,
        private bool       $disableCache,
    ) {
    }

    public function create(string $cacheFilenamePrefix, string $type): ?AssetMergeInterface
    {
        return $this->disableCache ? null : new AssetMerge(
            $this->httpClient,
            $this->logger,
            $this->publicCacheDir,
            $this->publicCachePath,
            $cacheFilenamePrefix,
            $type,
            $this->debug
        );
    }
}
