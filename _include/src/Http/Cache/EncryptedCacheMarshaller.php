<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/** Serializes and authenticates volatile-cache values with XChaCha20-Poly1305. */
final readonly class EncryptedCacheMarshaller implements MarshallerInterface
{
    private const string ENVELOPE = "REGISTER-CACHE\x01";

    private MarshallerInterface $marshaller;

    /**
     * The first key encrypts new values. Remaining keys are accepted while rotating a key.
     *
     * @param list<string> $keys
     */
    public function __construct(
        #[\SensitiveParameter]
        private array $keys,
        ?MarshallerInterface $marshaller = null,
    ) {
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('The sodium extension is required for encrypted volatile caching.');
        }

        if ($keys === []) {
            throw new \InvalidArgumentException('At least one volatile-cache encryption key is required.');
        }

        foreach ($keys as $key) {
            if (\strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \InvalidArgumentException('A volatile-cache encryption key has an invalid length.');
            }
        }

        $this->marshaller = $marshaller ?? new DefaultMarshaller();
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<array-key, mixed>|null $failed
     * @return array<array-key, string>
     */
    #[\Override]
    public function marshall(array $values, ?array &$failed): array
    {
        $marshalled = $this->marshaller->marshall($values, $failed);

        foreach ($marshalled as $key => $value) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $marshalled[$key] = self::ENVELOPE . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $value,
                self::ENVELOPE,
                $nonce,
                $this->keys[0],
            );
        }

        return $marshalled;
    }

    #[\Override]
    public function unmarshall(string $value): mixed
    {
        $headerLength = \strlen(self::ENVELOPE);
        $nonceLength  = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $minimumLength = $headerLength + $nonceLength
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

        if (\strlen($value) < $minimumLength || !str_starts_with($value, self::ENVELOPE)) {
            throw new CacheIntegrityException('The volatile-cache envelope is invalid.');
        }

        $nonce      = substr($value, $headerLength, $nonceLength);
        $ciphertext = substr($value, $headerLength + $nonceLength);

        foreach ($this->keys as $key) {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                self::ENVELOPE,
                $nonce,
                $key,
            );
            if ($plaintext !== false) {
                return $this->marshaller->unmarshall($plaintext);
            }
        }

        throw new CacheIntegrityException('The volatile-cache value failed authentication.');
    }
}
