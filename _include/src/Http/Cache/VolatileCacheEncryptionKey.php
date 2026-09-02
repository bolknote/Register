<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Holds decoded volatile-cache key material and its non-secret namespace fingerprint. */
final readonly class VolatileCacheEncryptionKey
{
    public string $fingerprint;

    public function __construct(
        #[\SensitiveParameter]
        public string $key,
    ) {
        if (\strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException('A volatile-cache encryption key has an invalid length.');
        }

        $this->fingerprint = substr(hash('sha256', $key), 0, 16);
    }
}
