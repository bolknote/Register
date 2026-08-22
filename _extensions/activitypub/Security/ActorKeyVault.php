<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

use S2\Cms\Config\DynamicSecretStore;

/** Encrypts every actor private key with a key-id-bound key derived from the private master key. */
final readonly class ActorKeyVault
{
    private const int MASTER_KEY_BYTES = 32;

    public function __construct(private DynamicSecretStore $secretStore)
    {
    }

    public function encrypt(string $keyPublicId, string $privateKeyPem): EncryptedPrivateKey
    {
        $this->validatePublicId($keyPublicId);
        if (!str_starts_with($privateKeyPem, '-----BEGIN PRIVATE KEY-----')) {
            throw new \InvalidArgumentException('Only PKCS#8 ActivityPub private keys can enter the vault.');
        }

        $key   = $this->deriveKey($keyPublicId);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $ciphertext = sodium_crypto_secretbox($privateKeyPem, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return new EncryptedPrivateKey($this->encode($ciphertext), $this->encode($nonce));
    }

    public function decrypt(string $keyPublicId, EncryptedPrivateKey $encryptedPrivateKey): string
    {
        $encodedMasterKey = $this->secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        if ($encodedMasterKey === null) {
            throw new \RuntimeException('The ActivityPub master key is missing; identity recovery is required.');
        }

        return $this->decryptWithMasterSecret($keyPublicId, $encryptedPrivateKey, $encodedMasterKey);
    }

    public function decryptWithMasterSecret(
        string              $keyPublicId,
        EncryptedPrivateKey $encryptedPrivateKey,
        string              $encodedMasterKey,
    ): string {
        $this->validatePublicId($keyPublicId);
        $ciphertext = $this->decode($encryptedPrivateKey->ciphertext, 'ciphertext');
        $nonce      = $this->decode($encryptedPrivateKey->nonce, 'nonce');
        if (\strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            || \strlen($ciphertext) <= SODIUM_CRYPTO_SECRETBOX_MACBYTES
        ) {
            throw new \RuntimeException('The encrypted ActivityPub private key envelope is invalid.');
        }

        $key = $this->deriveKey($keyPublicId, $encodedMasterKey);
        try {
            $privateKeyPem = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        if ($privateKeyPem === false) {
            throw new \RuntimeException('The ActivityPub private key failed authenticated decryption.');
        }

        if (!str_starts_with($privateKeyPem, '-----BEGIN PRIVATE KEY-----')) {
            throw new \RuntimeException('The ActivityPub private key failed authenticated decryption.');
        }

        return $privateKeyPem;
    }

    private function deriveKey(string $keyPublicId, ?string $encodedMasterKey = null): string
    {
        $encodedMasterKey ??= $this->secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        if ($encodedMasterKey === null) {
            throw new \RuntimeException('The ActivityPub master key is missing; identity recovery is required.');
        }

        $masterKey        = $this->decode($encodedMasterKey, 'master key');
        if (\strlen($masterKey) !== self::MASTER_KEY_BYTES) {
            sodium_memzero($masterKey);
            throw new \RuntimeException('The ActivityPub master key has an invalid size.');
        }

        try {
            return hash_hkdf(
                'sha256',
                $masterKey,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                'Register ActivityPub actor private key v1',
                $keyPublicId,
            );
        } finally {
            sodium_memzero($masterKey);
        }
    }

    private function validatePublicId(string $keyPublicId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $keyPublicId) !== 1) {
            throw new \InvalidArgumentException('The ActivityPub key identifier is invalid.');
        }
    }

    private function encode(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function decode(string $value, string $name): string
    {
        try {
            $decoded = sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('The ActivityPub ' . $name . ' encoding is invalid.', 0, $throwable);
        }

        if (!hash_equals($value, $this->encode($decoded))) {
            sodium_memzero($decoded);
            throw new \RuntimeException('The ActivityPub ' . $name . ' encoding is not canonical.');
        }

        return $decoded;
    }
}
