<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Owns and continuously verifies the private boundary inside a shared tmpfs mount. */
final readonly class SecureVolatileCacheDirectory
{
    public function __construct(public string $path)
    {
        if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('A volatile-cache directory must be absolute.');
        }
    }

    /** @phpstan-impure */
    public function ensure(): bool
    {
        clearstatcache(true, $this->path);
        if (is_link($this->path)) {
            return false;
        }

        if (!is_dir($this->path)
            && !register_call_without_warnings(fn(): bool => mkdir($this->path, 0700, true))
            && !is_dir($this->path)
        ) {
            return false;
        }

        $stat = register_call_without_warnings(fn(): array|false => lstat($this->path));
        if (!\is_array($stat) || ($stat['mode'] & 0170000) !== 0040000) {
            return false;
        }

        if (\function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            return false;
        }

        if (($stat['mode'] & 0777) !== 0700
            && !register_call_without_warnings(fn(): bool => chmod($this->path, 0700))
        ) {
            return false;
        }

        clearstatcache(true, $this->path);
        $permissions = fileperms($this->path);

        return $permissions !== false
            && ($permissions & 0777) === 0700
            && is_writable($this->path);
    }

    public function prunePageCacheNamespaces(string $activeNamespace): void
    {
        if (preg_match('/^pages(?:_v[1-9][0-9]*)?_[a-f0-9]{16}$/D', $activeNamespace) !== 1
            || !$this->ensure()
        ) {
            return;
        }

        if (is_dir($this->path . DIRECTORY_SEPARATOR . $activeNamespace)
            && !is_link($this->path . DIRECTORY_SEPARATOR . $activeNamespace)
        ) {
            return;
        }

        $entries = register_call_without_warnings(fn(): array|false => scandir(
            $this->path,
            SCANDIR_SORT_NONE,
        ));
        if (!\is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === $activeNamespace
                || preg_match('/^pages(?:_v[1-9][0-9]*)?_[a-f0-9]{16}$/D', $entry) !== 1
            ) {
                continue;
            }

            $this->remove($this->path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            register_call_without_warnings(static fn(): bool => unlink($path));

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }

                if ($entry->isLink() || $entry->isFile()) {
                    register_call_without_warnings(static fn(): bool => unlink($entry->getPathname()));
                } else {
                    register_call_without_warnings(static fn(): bool => rmdir($entry->getPathname()));
                }
            }
        } catch (\Throwable) {
            return;
        }

        register_call_without_warnings(static fn(): bool => rmdir($path));
    }
}
