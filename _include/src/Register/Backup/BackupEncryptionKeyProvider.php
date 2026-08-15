<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use S2\Cms\Config\StringProxy;

final readonly class BackupEncryptionKeyProvider
{
    public const int KEY_BYTES = 32;

    public const string SYMMETRIC_VERSION = "\x01";

    public const string RECIPIENT_VERSION = "\x02";

    private const int RECIPIENT_KEY_BYTES = 32;

    public function __construct(
        private StringProxy|string $secret,
        private ?string            $recipientPublicKey = null,
        private ?string            $recipientPrivateKey = null,
    ) {
    }

    public function key(): string
    {
        $secret = $this->secret instanceof StringProxy ? $this->secret->get() : $this->secret;
        if (\strlen($secret) < self::KEY_BYTES) {
            throw new \RuntimeException(
                'Backup encryption requires a stable secret containing at least 32 bytes.',
            );
        }

        return hash_hkdf(
            'sha256',
            $secret,
            self::KEY_BYTES,
            'Register backup encryption key v1',
        );
    }

    /** @return array{version: string, key: string, keyEnvelope: string} */
    public function encryptionMaterial(): array
    {
        if ($this->recipientPublicKey === null || $this->recipientPublicKey === '') {
            return [
                'version'     => self::SYMMETRIC_VERSION,
                'key'         => $this->key(),
                'keyEnvelope' => '',
            ];
        }

        $publicKey = $this->decodeRecipientKey($this->recipientPublicKey, 'public');
        $key       = random_bytes(self::KEY_BYTES);
        try {
            $keyEnvelope = sodium_crypto_box_seal($key, $publicKey);
        } catch (\Throwable $throwable) {
            sodium_memzero($key);
            throw new \RuntimeException('Unable to wrap the backup encryption key.', 0, $throwable);
        }

        return [
            'version'     => self::RECIPIENT_VERSION,
            'key'         => $key,
            'keyEnvelope' => $keyEnvelope,
        ];
    }

    public function decryptionKey(string $version, string $keyEnvelope): string
    {
        if (hash_equals(self::SYMMETRIC_VERSION, $version)) {
            if ($keyEnvelope !== '') {
                throw new \RuntimeException('The symmetric backup key envelope is invalid.');
            }

            return $this->key();
        }

        if (!hash_equals(self::RECIPIENT_VERSION, $version)) {
            throw new \RuntimeException('The backup encryption version is not supported.');
        }

        if (\strlen($keyEnvelope) !== self::KEY_BYTES + SODIUM_CRYPTO_BOX_SEALBYTES) {
            throw new \RuntimeException('The recipient backup key envelope is invalid.');
        }

        if ($this->recipientPrivateKey === null || $this->recipientPrivateKey === '') {
            throw new \RuntimeException(
                'The offline backup recipient private key is required to decrypt this archive.',
            );
        }

        $privateKey = $this->decodeRecipientKey($this->recipientPrivateKey, 'private');
        $publicKey  = $this->recipientPublicKey === null || $this->recipientPublicKey === ''
            ? sodium_crypto_box_publickey_from_secretkey($privateKey)
            : $this->decodeRecipientKey($this->recipientPublicKey, 'public');
        $derivedPublicKey = sodium_crypto_box_publickey_from_secretkey($privateKey);
        if (!hash_equals($derivedPublicKey, $publicKey)) {
            sodium_memzero($privateKey);
            throw new \RuntimeException('The backup recipient public and private keys do not match.');
        }

        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($privateKey, $publicKey);
        try {
            $key = sodium_crypto_box_seal_open($keyEnvelope, $keyPair);
        } finally {
            sodium_memzero($privateKey);
            sodium_memzero($keyPair);
        }

        if (!\is_string($key) || \strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('The wrapped backup encryption key failed authentication.');
        }

        return $key;
    }

    private function decodeRecipientKey(string $encoded, string $name): string
    {
        try {
            $decoded = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('The backup recipient ' . $name . ' key is invalid.', 0, $throwable);
        }

        if (\strlen($decoded) !== self::RECIPIENT_KEY_BYTES
            || !hash_equals(
                $encoded,
                sodium_bin2base64($decoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            )
        ) {
            throw new \RuntimeException('The backup recipient ' . $name . ' key is invalid.');
        }

        return $decoded;
    }
}
