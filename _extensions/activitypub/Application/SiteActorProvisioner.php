<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\NewLocalActor;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\RsaCrypto;

/** Creates an unpublished site identity. Activation remains a separate, fully verified operation. */
final readonly class SiteActorProvisioner
{
    public function __construct(
        private FederationStateRepository  $stateRepository,
        private LocalActorRepository        $actorRepository,
        private PublicIdGenerator           $publicIdGenerator,
        private RsaCrypto                   $rsaCrypto,
        private ActorKeyVault               $keyVault,
        private PortableDatabaseTransaction $transaction,
        private PortableHtmlSanitizer        $htmlSanitizer,
    ) {
    }

    public function provision(SiteActorDraft $draft, ?int $now = null): LocalActor
    {
        if ($this->stateRepository->lifecycleState() !== FederationLifecycleState::INSTALLED) {
            throw new \DomainException('A site actor can only be provisioned before federation activation.');
        }

        if ($this->actorRepository->siteActor() instanceof LocalActor) {
            throw new \DomainException('The ActivityPub site actor has already been provisioned.');
        }

        $actorPublicId = $this->publicIdGenerator->generate();
        $keyPublicId   = $this->publicIdGenerator->generate();
        $keyPair       = $this->rsaCrypto->generateKeyPair();
        $privateKey    = $this->keyVault->encrypt($keyPublicId, $keyPair->privateKeyPem);
        $timestamp     = $now ?? time();

        return $this->transaction->run(function () use (
            $actorPublicId,
            $draft,
            $keyPair,
            $keyPublicId,
            $privateKey,
            $timestamp,
        ): LocalActor {
            if ($this->actorRepository->siteActor() instanceof LocalActor) {
                throw new \DomainException('The ActivityPub site actor has already been provisioned.');
            }

            $metadata = array_map(fn(array $entry): array => [
                'name'  => trim(strip_tags($entry['name'])),
                'value' => $this->htmlSanitizer->sanitize($entry['value'], $draft->profileUrl),
            ], $draft->metadata);
            $actorId = $this->actorRepository->insert(new NewLocalActor(
                $actorPublicId,
                ActorKind::SITE,
                null,
                $draft->type,
                $draft->handle,
                $draft->displayName,
                $this->htmlSanitizer->sanitize($draft->summaryHtml, $draft->profileUrl),
                $draft->profileUrl,
                $draft->avatar,
                $draft->header,
                $metadata,
                $draft->discoverable,
            ), LocalActorState::DRAFT, $timestamp);
            $this->actorRepository->insertKey(
                $actorId,
                $keyPublicId,
                $keyPair->publicKeyPem,
                $privateKey,
                $timestamp,
            );

            return $this->actorRepository->findByPublicId($actorPublicId)
                ?? throw new \RuntimeException('The provisioned ActivityPub actor cannot be reloaded.');
        });
    }

    /** Creates the private identity once and safely edits only its unpublished profile afterwards. */
    public function provisionOrUpdate(SiteActorDraft $draft, ?int $now = null): LocalActor
    {
        if ($this->stateRepository->lifecycleState() !== FederationLifecycleState::INSTALLED) {
            throw new \DomainException('A site actor can only be prepared before federation activation.');
        }

        $existing = $this->actorRepository->siteActor();
        if (!$existing instanceof LocalActor) {
            return $this->provision($draft, $now);
        }

        if ($existing->state !== LocalActorState::DRAFT) {
            throw new \DomainException('The ActivityPub site actor is no longer an editable draft.');
        }

        $timestamp = $now ?? time();
        return $this->transaction->run(function () use ($existing, $draft, $timestamp): LocalActor {
            $metadata = array_map(fn(array $entry): array => [
                'name'  => trim(strip_tags($entry['name'])),
                'value' => $this->htmlSanitizer->sanitize($entry['value'], $draft->profileUrl),
            ], $draft->metadata);

            return $this->actorRepository->updateDraft($existing->id, new NewLocalActor(
                $existing->publicId,
                ActorKind::SITE,
                null,
                $draft->type,
                $draft->handle,
                $draft->displayName,
                $this->htmlSanitizer->sanitize($draft->summaryHtml, $draft->profileUrl),
                $draft->profileUrl,
                $draft->avatar,
                $draft->header,
                $metadata,
                $draft->discoverable,
            ), $timestamp);
        });
    }
}
