<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

use s2_extensions\activitypub\Security\EncryptedPrivateKey;

final readonly class LocalActorKey
{
    public function __construct(
        public int                 $id,
        public int                 $actorId,
        public string              $publicId,
        public string              $algorithm,
        public string              $publicKeyPem,
        public EncryptedPrivateKey $encryptedPrivateKey,
        public bool                $current,
        public int                 $createdAt,
        public ?int                $retiredAt,
        public ?int                $destroyedAt,
    ) {
    }
}
