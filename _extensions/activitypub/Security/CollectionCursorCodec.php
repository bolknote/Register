<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

use S2\Cms\Config\DynamicSecretStore;
use s2_extensions\activitypub\Domain\CollectionAnchor;

/** Deterministic authenticated encryption keeps collection cursors stable without exposing DB keys. */
final readonly class CollectionCursorCodec
{
    private const int MASTER_KEY_BYTES = 32;

    private const int MAX_CURSOR_BYTES = 512;

    public function __construct(private DynamicSecretStore $secretStore)
    {
    }

    public function encode(string $scope, CollectionAnchor $anchor): string
    {
        $this->validateScope($scope);
        $payload = "1\0" . $scope . "\0" . $anchor->timestamp . "\0" . $anchor->id;
        $key      = $this->key('encryption');
        $nonceKey = $this->key('nonce');
        try {
            $nonce = substr(hash_hmac('sha256', $payload, $nonceKey, true), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $token = $nonce . sodium_crypto_secretbox($payload, $nonce, $key);
        } finally {
            sodium_memzero($key);
            sodium_memzero($nonceKey);
        }

        return sodium_bin2base64($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decode(string $scope, string $cursor): CollectionAnchor
    {
        $this->validateScope($scope);
        if ($cursor === '' || \strlen($cursor) > self::MAX_CURSOR_BYTES) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor has an invalid size.');
        }

        try {
            $token = sodium_base642bin($cursor, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable $throwable) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor encoding is invalid.', 0, $throwable);
        }

        if (!hash_equals($cursor, sodium_bin2base64($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))
            || \strlen($token) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
        ) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor encoding is not canonical.');
        }

        $nonce     = substr($token, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($token, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key       = $this->key('encryption');
        try {
            $payload = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        if ($payload === false) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor authentication failed.');
        }

        $parts = explode("\0", $payload);
        if (\count($parts) !== 4
            || $parts[0] !== '1'
            || !hash_equals($scope, $parts[1])
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $parts[2]) !== 1
            || preg_match('/^[1-9][0-9]*$/D', $parts[3]) !== 1
        ) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor payload is invalid.');
        }

        $timestamp = filter_var($parts[2], FILTER_VALIDATE_INT);
        $id        = filter_var($parts[3], FILTER_VALIDATE_INT);
        if (!\is_int($timestamp) || !\is_int($id)) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor is outside the platform integer range.');
        }

        return new CollectionAnchor($timestamp, $id);
    }

    private function key(string $purpose): string
    {
        $encoded = $this->secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        if ($encoded === null) {
            throw new \RuntimeException('The ActivityPub master key is missing; identity recovery is required.');
        }

        try {
            $masterKey = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('The ActivityPub master key encoding is invalid.', 0, $throwable);
        }

        if (!hash_equals($encoded, sodium_bin2base64($masterKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))
            || \strlen($masterKey) !== self::MASTER_KEY_BYTES
        ) {
            sodium_memzero($masterKey);
            throw new \RuntimeException('The ActivityPub master key is invalid.');
        }

        try {
            return hash_hkdf(
                'sha256',
                $masterKey,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                'Register ActivityPub collection cursor v1 ' . $purpose,
            );
        } finally {
            sodium_memzero($masterKey);
        }
    }

    private function validateScope(string $scope): void
    {
        if ($scope === '' || \strlen($scope) > 128 || preg_match('/^[A-Za-z0-9_:\/-]+$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('The ActivityPub collection cursor scope is invalid.');
        }
    }
}
