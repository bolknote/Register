<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;

/** The only phpseclib-facing boundary; a future stable phpseclib 4 migration stays local. */
final class RsaCrypto
{
    private const int KEY_BITS = 2048;

    public function generateKeyPair(): RsaKeyPair
    {
        try {
            $privateKey = RSA::createKey(self::KEY_BITS);

            return new RsaKeyPair(
                $privateKey->getPublicKey()->toString('PKCS8'),
                $privateKey->toString('PKCS8'),
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Unable to generate the ActivityPub RSA key pair.', 0, $throwable);
        }
    }

    public function sign(string $privateKeyPem, string $payload): string
    {
        try {
            return $this->privateKey($privateKeyPem)
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1)
                ->sign($payload)
            ;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Unable to create an ActivityPub RSA signature.', 0, $throwable);
        }
    }

    public function verify(string $publicKeyPem, string $payload, string $signature): bool
    {
        try {
            return $this->publicKey($publicKeyPem)
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1)
                ->verify($payload, $signature)
            ;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Parses an untrusted RSA key and returns one canonical SubjectPublicKeyInfo PEM. */
    public function normalizePublicKey(string $publicKeyPem): string
    {
        try {
            return $this->publicKey($publicKeyPem)->toString('PKCS8');
        } catch (\Throwable $throwable) {
            throw new \InvalidArgumentException('The remote ActivityPub public key is not a usable RSA key.', 0, $throwable);
        }
    }

    private function privateKey(string $privateKeyPem): PrivateKey
    {
        $privateKey = PublicKeyLoader::loadPrivateKey($privateKeyPem);
        if (!$privateKey instanceof PrivateKey) {
            throw new \UnexpectedValueException('The ActivityPub signing key is not RSA.');
        }

        return $privateKey;
    }

    private function publicKey(string $publicKeyPem): PublicKey
    {
        $publicKey = PublicKeyLoader::loadPublicKey($publicKeyPem);
        if (!$publicKey instanceof PublicKey) {
            throw new \UnexpectedValueException('The ActivityPub verification key is not RSA.');
        }

        return $publicKey;
    }
}
