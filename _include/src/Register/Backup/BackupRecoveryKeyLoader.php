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

        $backupSecret = $this->symmetricSecret($config, $applicationRoot);
        $recipientPublicKey = $config['backups']['recipient_public_key'] ?? null;
        $recipientPrivateKey = $config['backups']['recipient_private_key'] ?? null;
        if (\is_string($recipientPublicKey) || \is_string($recipientPrivateKey)) {
            return new BackupEncryptionKeyProvider(
                $backupSecret ?? '',
                \is_string($recipientPublicKey) ? $recipientPublicKey : null,
                \is_string($recipientPrivateKey) ? $recipientPrivateKey : null,
            );
        }

        if ($backupSecret !== null) {
            return new BackupEncryptionKeyProvider($backupSecret);
        }

        throw new \RuntimeException(
            'No backup recovery key was found. Restore the matching symmetric or recipient key first.',
        );
    }

    /**
     * @param array<mixed> $config
     */
    private function symmetricSecret(array $config, string $applicationRoot): ?string
    {
        $backupSecret = $config['backups']['encryption_key'] ?? null;
        if (\is_string($backupSecret) && \strlen($backupSecret) >= BackupEncryptionKeyProvider::KEY_BYTES) {
            return $backupSecret;
        }

        $staticSecret = $config['security']['antispam_secret'] ?? null;
        if (\is_string($staticSecret) && \strlen($staticSecret) >= BackupEncryptionKeyProvider::KEY_BYTES) {
            return $staticSecret;
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
                return $dynamicSecret;
            }
        }

        return null;
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
