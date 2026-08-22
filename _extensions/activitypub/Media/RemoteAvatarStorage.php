<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

/** Private derived-file storage. Public access is exclusively through the bounded media controller. */
final readonly class RemoteAvatarStorage
{
    private string $directory;

    public function __construct(string $cacheDirectory)
    {
        if ($cacheDirectory === '' || str_contains($cacheDirectory, "\0")) {
            throw new \InvalidArgumentException('A private cache directory is required for remote avatars.');
        }

        $this->directory = rtrim($cacheDirectory, '/\\') . '/activitypub/avatars';
    }

    public function publish(string $content, InspectedRemoteAvatar $image, string $publicId): string
    {
        $this->validatePublicId($publicId);
        if (!hash_equals($image->contentHash, hash('sha256', $content))
            || $image->byteSize !== \strlen($content)
        ) {
            throw new \InvalidArgumentException('Remote avatar bytes changed after inspection.');
        }

        $storageKey = substr($publicId, 0, 2) . '/' . substr($publicId, 2, 2) . '/'
            . $publicId . '-' . substr($image->contentHash, 0, 16) . '.' . $image->extension;
        $filename = $this->path($storageKey);
        $directory = dirname($filename);
        $this->ensureDirectory($this->directory);
        $this->ensureDenyPolicy();
        $this->ensureDirectory($directory);

        if (is_file($filename)) {
            return $storageKey;
        }

        $temporary = tempnam($directory, '.avatar-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate a temporary remote avatar file.');
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== $image->byteSize) {
                throw new \RuntimeException('Unable to write a complete remote avatar file.');
            }

            s2_call_without_warnings(static fn(): bool => chmod($temporary, 0600));
            if (!rename($temporary, $filename)) {
                throw new \RuntimeException('Unable to atomically publish a remote avatar file.');
            }
        } finally {
            if (is_file($temporary)) {
                s2_call_without_warnings(static fn(): bool => unlink($temporary));
            }
        }

        return $storageKey;
    }

    public function path(string $storageKey): string
    {
        if (preg_match('#^[A-Za-z0-9_-]{2}/[A-Za-z0-9_-]{2}/[A-Za-z0-9_.-]{1,220}$#D', $storageKey) !== 1
            || str_contains($storageKey, '..')
        ) {
            throw new \InvalidArgumentException('A remote avatar storage key is invalid.');
        }

        return $this->directory . '/' . $storageKey;
    }

    public function remove(string $storageKey): void
    {
        $filename = $this->path($storageKey);
        if (is_file($filename) && !s2_call_without_warnings(static fn(): bool => unlink($filename))) {
            throw new \RuntimeException('Unable to remove a retired remote avatar file.');
        }
    }

    public function matches(string $storageKey, string $contentHash, int $byteSize): bool
    {
        if ($storageKey === '' || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1 || $byteSize < 1) {
            return false;
        }

        try {
            $filename = $this->path($storageKey);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $warning = null;
        $content = s2_call_without_warnings(static fn(): string|false => file_get_contents($filename), $warning);
        unset($warning);

        return \is_string($content)
            && \strlen($content) === $byteSize
            && hash_equals($contentHash, hash('sha256', $content));
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the private remote avatar directory.');
        }

        s2_call_without_warnings(static fn(): bool => chmod($directory, 0700));
    }

    private function ensureDenyPolicy(): void
    {
        $filename = $this->directory . '/.htaccess';
        $policy = "Options -Indexes -ExecCGI\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        $existing = is_file($filename)
            ? s2_call_without_warnings(static fn(): string|false => file_get_contents($filename))
            : false;
        if ($existing === $policy) {
            return;
        }

        $temporary = tempnam($this->directory, '.policy-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate a remote avatar access policy file.');
        }

        try {
            if (file_put_contents($temporary, $policy, LOCK_EX) !== \strlen($policy)
                || !rename($temporary, $filename)
            ) {
                throw new \RuntimeException('Unable to install the remote avatar access policy.');
            }

            s2_call_without_warnings(static fn(): bool => chmod($filename, 0644));
        } finally {
            if (is_file($temporary)) {
                s2_call_without_warnings(static fn(): bool => unlink($temporary));
            }
        }
    }

    private function validatePublicId(string $publicId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            throw new \InvalidArgumentException('A remote avatar public identifier is invalid.');
        }
    }
}
