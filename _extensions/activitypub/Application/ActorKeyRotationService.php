<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorKey;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\RsaCrypto;

/** Generates, verifies, encrypts and atomically promotes a new actor signing key. */
final readonly class ActorKeyRotationService
{
    public function __construct(
        private FederationStateRepository   $stateRepository,
        private LocalActorRepository        $actorRepository,
        private PublicIdGenerator           $publicIdGenerator,
        private RsaCrypto                   $rsaCrypto,
        private ActorKeyVault               $keyVault,
        private PortableDatabaseTransaction $transaction,
    ) {
    }

    public function rotate(int $actorId, ?int $now = null): LocalActorKey
    {
        $state = $this->stateRepository->lifecycleState();
        if (!\in_array($state, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)) {
            throw new \DomainException('ActivityPub keys can only be rotated for a published identity.');
        }

        $actor = $this->actorRepository->findById($actorId);
        if (!$actor instanceof LocalActor) {
            throw new \DomainException('The ActivityPub actor is not active.');
        }

        if ($actor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The ActivityPub actor is not active.');
        }

        $publicId = $this->publicIdGenerator->generate();
        $pair = $this->rsaCrypto->generateKeyPair();
        $privateKeyPem = $pair->privateKeyPem;
        $probe = random_bytes(32);
        if (!$this->rsaCrypto->verify($pair->publicKeyPem, $probe, $this->rsaCrypto->sign($privateKeyPem, $probe))) {
            throw new \RuntimeException('The generated ActivityPub signing key failed its self-test.');
        }

        $encrypted = $this->keyVault->encrypt($publicId, $privateKeyPem);
        $timestamp = $now ?? time();
        try {
            return $this->transaction->run(fn(): LocalActorKey => $this->actorRepository->replaceCurrentKey(
                $actor->id,
                $publicId,
                $pair->publicKeyPem,
                $encrypted,
                $timestamp,
            ));
        } finally {
            sodium_memzero($privateKeyPem);
        }
    }
}
