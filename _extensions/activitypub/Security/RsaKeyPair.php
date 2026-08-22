<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

final readonly class RsaKeyPair
{
    public function __construct(
        public string $publicKeyPem,
        public string $privateKeyPem,
    ) {
        if (!str_starts_with($publicKeyPem, '-----BEGIN PUBLIC KEY-----')
            || !str_starts_with($privateKeyPem, '-----BEGIN PRIVATE KEY-----')
        ) {
            throw new \InvalidArgumentException('RSA key material must use unencrypted PKCS#8 PEM.');
        }
    }
}
