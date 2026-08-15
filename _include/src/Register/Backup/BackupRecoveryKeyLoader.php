<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use S2\Cms\Config\SecretConfigPathResolver;
use S2\Cms\Config\StaticConfigLoader;

/** Loads only the separately stored key material needed for an offline backup recovery. */
final class BackupRecoveryKeyLoader
{
    public function fromConfigFile(string $configFilename): BackupEncryptionKeyProvider
    {
        $configFilename = realpath($configFilename);
        if ($configFilename === false || !is_file($configFilename) || is_link($configFilename)) {
            throw new \RuntimeException('The Register recovery configuration is not a regular file.');
        }

        $config          = (new StaticConfigLoader())->load($configFilename);
        $applicationRoot = \dirname($configFilename);

        $backupSecret = $config['backups']['encryption_key'] ?? null;
        if (\is_string($backupSecret) && \strlen($backupSecret) >= BackupEncryptionKeyProvider::KEY_BYTES) {
            return new BackupEncryptionKeyProvider($backupSecret);
        }

        $staticSecret = $config['security']['antispam_secret'] ?? null;
        if (\is_string($staticSecret) && \strlen($staticSecret) >= BackupEncryptionKeyProvider::KEY_BYTES) {
            return new BackupEncryptionKeyProvider($staticSecret);
        }

        $configuredSecretFile = $config['security']['secret_file'] ?? null;
        $candidates = [];
        if (\is_string($configuredSecretFile) && trim($configuredSecretFile) !== '') {
            $candidates[] = SecretConfigPathResolver::resolve(
                $applicationRoot,
                $applicationRoot,
                $configuredSecretFile,
            );
        } else {
            $candidates[] = $applicationRoot . '/' . SecretConfigPathResolver::fallbackFilename();
            $candidates[] = SecretConfigPathResolver::resolve($applicationRoot, $applicationRoot, null);
        }

        foreach (array_unique($candidates) as $candidate) {
            $dynamicSecret = $this->dynamicAntispamSecret($candidate);
            if ($dynamicSecret !== null) {
                return new BackupEncryptionKeyProvider($dynamicSecret);
            }
        }

        throw new \RuntimeException(
            'No backup encryption key was found. Restore the original config.php and config.secrets.php first.',
        );
    }

    private function dynamicAntispamSecret(string $filename): ?string
    {
        if (!is_file($filename) || is_link($filename)) {
            return null;
        }

        $secrets = s2_call_without_warnings(
            static fn(): mixed => (static fn(string $path): mixed => include $path)($filename),
        );
        if (!\is_array($secrets)) {
            throw new \RuntimeException('The Register private secret file is invalid.');
        }

        $secret = $secrets['S2_ANTISPAM_SECRET'] ?? null;

        return \is_string($secret) && \strlen($secret) >= BackupEncryptionKeyProvider::KEY_BYTES
            ? $secret
            : null;
    }
}
