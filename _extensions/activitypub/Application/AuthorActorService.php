<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Author\AuthorProfileRepository;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\NewLocalActor;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Presentation\ActorDocumentBuilder;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\RsaCrypto;

/** Creates or edits an opted-in Person actor without exposing the author's authentication identity. */
final readonly class AuthorActorService
{
    public function __construct(
        private AuthorProfileRepository       $authorProfileRepository,
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $actorRepository,
        private LocalFederationRepository     $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private RsaCrypto                     $rsaCrypto,
        private ActorKeyVault                 $keyVault,
        private PortableHtmlSanitizer         $htmlSanitizer,
        private ActorDocumentBuilder          $actorDocumentBuilder,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private PortableDatabaseTransaction   $transaction,
    ) {
    }

    public function save(AuthorActorDraft $draft, ?int $now = null): LocalActor
    {
        $timestamp = $now ?? time();
        if ($timestamp < 1 || !\in_array($this->stateRepository->lifecycleState(), [
            FederationLifecycleState::ACTIVE,
            FederationLifecycleState::PAUSED,
        ], true)) {
            throw new \DomainException('Author ActivityPub identities require published federation.');
        }

        $author = $this->authorProfileRepository->find($draft->authorId);
        if (!$author instanceof \Register\Author\AuthorProfile) {
            throw new \DomainException('Only an existing Register publisher may enable an ActivityPub author identity.');
        }

        if (!$author->canPublish) {
            throw new \DomainException('Only an existing Register publisher may enable an ActivityPub author identity.');
        }

        $existing = $this->actorRepository->authorActor($draft->authorId);
        if ($existing instanceof LocalActor) {
            if ($existing->state !== LocalActorState::ACTIVE) {
                throw new \DomainException('A retired ActivityPub author identity cannot be silently reused.');
            }

            return $this->update($existing, $draft, $timestamp);
        }

        return $this->create($draft, $timestamp);
    }

    private function create(AuthorActorDraft $draft, int $now): LocalActor
    {
        $actorPublicId = $this->publicIdGenerator->generate();
        $keyPublicId   = $this->publicIdGenerator->generate();
        $keyPair       = $this->rsaCrypto->generateKeyPair();
        $privateKey    = $this->keyVault->encrypt($keyPublicId, $keyPair->privateKeyPem);
        $input         = $this->input($draft, $actorPublicId);

        return $this->transaction->run(function () use (
            $draft,
            $input,
            $keyPair,
            $keyPublicId,
            $privateKey,
            $now,
        ): LocalActor {
            if ($this->actorRepository->authorActor($draft->authorId) instanceof LocalActor) {
                throw new \DomainException('The Register author already has an ActivityPub identity.');
            }

            $actorId = $this->actorRepository->insert($input, LocalActorState::ACTIVE, $now);
            $this->actorRepository->insertKey(
                $actorId,
                $keyPublicId,
                $keyPair->publicKeyPem,
                $privateKey,
                $now,
            );

            return $this->actorRepository->findById($actorId)
                ?? throw new \RuntimeException('The ActivityPub author identity cannot be reloaded.');
        });
    }

    private function update(LocalActor $existing, AuthorActorDraft $draft, int $now): LocalActor
    {
        return $this->transaction->run(function () use ($existing, $draft, $now): LocalActor {
            $current = $this->actorRepository->findById($existing->id);
            if (!$current instanceof LocalActor) {
                throw new \DomainException('The ActivityPub author identity changed before it could be updated.');
            }

            if ($current->state !== LocalActorState::ACTIVE) {
                throw new \DomainException('The ActivityPub author identity changed before it could be updated.');
            }

            $input = $this->input($draft, $current->publicId);
            if ($this->sameProfile($current, $input)) {
                return $current;
            }

            if (!hash_equals($current->handle, $input->handle->value)) {
                $current = $this->actorRepository->changeHandle($current->id, $input->handle, $now);
                $input   = $this->input($draft, $current->publicId);
            }

            $updated  = $this->actorRepository->updateActiveProfile($current->id, $input, $now);
            $publicId = $this->publicIdGenerator->generate();
            $document = $this->activityBuilder->updateActor(
                $publicId,
                $updated,
                $this->urlGeneratorFactory->create(),
                $this->actorDocumentBuilder->build($updated),
                $now,
            );
            $serialized = $this->canonicalJson->encode($document);
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $publicId,
                $updated->id,
                null,
                'Update',
                'public',
                ActivityDeliveryIntent::FOLLOWERS,
                'author-profile-update:' . $publicId,
                $serialized,
                hash('sha256', $serialized),
                $now,
                $now,
            ));
            $this->deliveryPlanner->plan($activity, $now);

            return $updated;
        });
    }

    private function input(AuthorActorDraft $draft, string $publicId): NewLocalActor
    {
        $metadata = array_map(fn(array $entry): array => [
            'name'  => trim(strip_tags($entry['name'])),
            'value' => $this->htmlSanitizer->sanitize($entry['value'], $draft->profileUrl),
        ], $draft->metadata);

        return new NewLocalActor(
            $publicId,
            ActorKind::AUTHOR,
            $draft->authorId,
            ActorType::PERSON,
            $draft->handle,
            $draft->displayName,
            $this->htmlSanitizer->sanitize($draft->summaryHtml, $draft->profileUrl),
            $draft->profileUrl,
            $draft->avatar,
            $draft->header,
            $metadata,
            $draft->discoverable,
        );
    }

    private function sameProfile(LocalActor $actor, NewLocalActor $input): bool
    {
        return hash_equals($actor->handle, $input->handle->value)
            && $actor->type === $input->type
            && hash_equals($actor->displayName, trim($input->displayName))
            && hash_equals($actor->summaryHtml, $input->summaryHtml)
            && hash_equals($actor->profileUrl, $input->profileUrl)
            && $actor->avatar === $input->avatar
            && $actor->header === $input->header
            && $actor->metadata === $input->metadata
            && $actor->discoverable === $input->discoverable;
    }
}
