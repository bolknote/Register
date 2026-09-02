<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Register\Core\Config\DynamicSecretStore;

/** Loads or creates the installation-specific volatile-cache key in config.secrets.php. */
final readonly class VolatileCacheEncryptionKeyProvider
{
    public const string SECRET_NAME = 'REGISTER_EXTENSION_PAGE_CACHE_KEY';

    public function __construct(private DynamicSecretStore $secretStore)
    {
    }

    public function get(): VolatileCacheEncryptionKey
    {
        $encoded = $this->secretStore->getExtensionPrivate(self::SECRET_NAME)
            ?? $this->secretStore->getOrCreateExtensionPrivate(
                self::SECRET_NAME,
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            );

        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $exception) {
            throw new \RuntimeException('The stored volatile-cache encryption key is invalid.', 0, $exception);
        }

        return new VolatileCacheEncryptionKey($key);
    }
}
