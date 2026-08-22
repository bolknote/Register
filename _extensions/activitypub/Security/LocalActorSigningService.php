<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;

/** Loads and decrypts a local actor key for only the duration of one signing operation. */
final readonly class LocalActorSigningService
{
    public function __construct(
        private LocalActorRepository          $actorRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private ActorKeyVault                 $keyVault,
        private LegacyHttpSignature           $legacySignature,
        private Rfc9421HttpSignature           $rfc9421Signature,
    ) {
    }

    public function signLegacy(
        int                  $actorId,
        HttpSignatureRequest $request,
        ?int                 $createdAt = null,
    ): SignedHttpHeaders {
        $key           = $this->key($actorId);
        $privateKeyPem = $this->keyVault->decrypt($key->publicId, $key->encryptedPrivateKey);
        try {
            return $this->legacySignature->sign(
                $request,
                $this->urlGeneratorFactory->create()->key($key->publicId),
                $privateKeyPem,
                $createdAt,
            );
        } finally {
            sodium_memzero($privateKeyPem);
        }
    }

    public function signRfc9421(
        int                  $actorId,
        HttpSignatureRequest $request,
        ?int                 $createdAt = null,
    ): SignedHttpHeaders {
        $key           = $this->key($actorId);
        $privateKeyPem = $this->keyVault->decrypt($key->publicId, $key->encryptedPrivateKey);
        try {
            return $this->rfc9421Signature->sign(
                $request,
                $this->urlGeneratorFactory->create()->key($key->publicId),
                $privateKeyPem,
                $createdAt,
            );
        } finally {
            sodium_memzero($privateKeyPem);
        }
    }

    private function key(int $actorId): LocalActorKey
    {
        $key = $this->actorRepository->currentKey($actorId);
        if (!$key instanceof LocalActorKey) {
            throw new \RuntimeException('The ActivityPub actor has no usable current signing key.');
        }

        if ($key->destroyedAt !== null) {
            throw new \RuntimeException('The ActivityPub actor has no usable current signing key.');
        }

        return $key;
    }
}
