<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

final readonly class EncryptedPrivateKey
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
    ) {
        if ($ciphertext === '' || $nonce === '') {
            throw new \InvalidArgumentException('Encrypted ActivityPub key material cannot be empty.');
        }
    }
}
