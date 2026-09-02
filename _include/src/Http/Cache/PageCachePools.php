<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Symfony\Contracts\Cache\CacheInterface;

/** Separates the durable page-cache pool from its bounded encrypted volatile tiers. */
final readonly class PageCachePools
{
    public function __construct(
        public CacheInterface $persistent,
        public CacheInterface $hot,
        public string         $filesystemDirectory,
        public bool           $sharedMemoryEnabled,
        public ?string        $sharedMemoryNamespace,
        public ?string        $tmpfsDirectory,
        public bool           $volatileEncryptionEnabled,
    ) {
    }
}
