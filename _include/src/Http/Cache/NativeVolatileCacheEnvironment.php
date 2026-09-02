<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Symfony\Component\Cache\Adapter\ApcuAdapter;

/** Discovers optional volatile cache backends available to the current PHP runtime. */
final readonly class NativeVolatileCacheEnvironment implements VolatileCacheEnvironmentInterface
{
    /** @param list<string> $tmpfsCandidates */
    public function __construct(
        private array $tmpfsCandidates = ['/dev/shm', '/run/shm'],
        private ?MemoryFilesystemInspector $filesystemInspector = null,
        private bool $allowCliTmpfs = false,
    ) {
    }

    #[\Override]
    public function apcuAvailable(): bool
    {
        if (!ApcuAdapter::isSupported() || !class_exists('APCUIterator', false)) {
            return false;
        }

        return PHP_SAPI !== 'cli'
            || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL);
    }

    #[\Override]
    public function tmpfsDirectory(string $applicationRoot): ?SecureVolatileCacheDirectory
    {
        // Page responses are produced by the web SAPI. Keeping CLI processes out of this tier also
        // prevents test runners and maintenance commands from sharing web-cache state accidentally.
        if (PHP_SAPI === 'cli' && !$this->allowCliTmpfs) {
            return null;
        }

        $inspector = $this->filesystemInspector ?? new MemoryFilesystemInspector();
        foreach ($this->tmpfsCandidates as $candidate) {
            $root = realpath($candidate);
            if ($root === false || !is_dir($root) || !is_writable($root)) {
                continue;
            }

            $memoryBacked = $inspector->isMemoryBacked($root);
            if ($memoryBacked !== true
                && ($memoryBacked !== null || !\in_array($root, ['/dev/shm', '/run/shm'], true))
            ) {
                continue;
            }

            $application = realpath($applicationRoot);
            $application = $application === false ? rtrim($applicationRoot, '/\\') : $application;
            $userId = (string)getmyuid();
            $directory = new SecureVolatileCacheDirectory(
                $root . '/register-cache-' . $userId . '-' . substr(hash('sha256', $application), 0, 16),
            );

            if ($directory->ensure()) {
                return $directory;
            }
        }

        return null;
    }
}
