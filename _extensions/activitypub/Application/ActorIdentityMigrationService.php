<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Delivery\DeliveryPlanner;
use s2_extensions\activitypub\Domain\ActivityDeliveryIntent;
use s2_extensions\activitypub\Domain\FederationLifecycleState;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Domain\LocalHandle;
use s2_extensions\activitypub\Domain\ModerationAction;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Domain\RemoteActor;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\NewStoredActivity;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation;
use s2_extensions\activitypub\Presentation\ActorDocumentBuilder;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Presentation\LocalActivityDocumentBuilder;

/** Durable local handle updates and verified one-way actor migrations. */
final readonly class ActorIdentityMigrationService
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $localActorRepository,
        private RemoteActorRepository         $remoteActorRepository,
        private ModerationRuleRepository      $moderationRepository,
        private LocalFederationRepository     $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private ActorDocumentBuilder          $actorDocumentBuilder,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private PortableDatabaseTransaction   $transaction,
    ) {
    }

    public function changeHandle(
        int         $localActorId,
        string      $newHandle,
        ?int        $now = null,
    ): ?StoredActivityRepresentation {
        $timestamp = $now ?? time();
        $this->assertPublishedLifecycle();
        $handle = new LocalHandle($newHandle);
        $actor  = $this->activeLocalActor($localActorId);
        if (hash_equals($actor->handle, $handle->value)) {
            return null;
        }

        return $this->transaction->run(function () use ($actor, $handle, $timestamp): StoredActivityRepresentation {
            $updatedActor = $this->localActorRepository->changeHandle($actor->id, $handle, $timestamp);
            $urls         = $this->urlGeneratorFactory->create();
            $publicId     = $this->publicIdGenerator->generate();
            $document     = $this->activityBuilder->updateActor(
                $publicId,
                $updatedActor,
                $urls,
                $this->actorDocumentBuilder->build($updatedActor),
                $timestamp,
            );
            $activity = $this->storeFollowersActivity(
                $publicId,
                $updatedActor,
                'Update',
                'actor-profile-update:' . $publicId,
                $document,
                $timestamp,
            );
            $this->deliveryPlanner->plan($activity, $timestamp);

            return $activity;
        });
    }

    public function move(
        int  $localActorId,
        int  $targetRemoteActorId,
        ?int $now = null,
    ): StoredActivityRepresentation {
        $timestamp = $now ?? time();
        $this->assertPublishedLifecycle();
        $actor  = $this->activeLocalActor($localActorId);
        $target = $this->remoteActorRepository->findById($targetRemoteActorId);
        if (!$target instanceof RemoteActor) {
            throw new \DomainException('The ActivityPub Move target must be a freshly verified active actor.');
        }

        if ($target->state !== 'active' || !$target->cacheIsFresh($timestamp)) {
            throw new \DomainException('The ActivityPub Move target must be a freshly verified active actor.');
        }

        if ($this->moderationRepository->decision($target) === ModerationAction::BLOCK) {
            throw new \DomainException('The ActivityPub Move target is blocked by local moderation policy.');
        }

        $urls     = $this->urlGeneratorFactory->create();
        $actorUrl = $urls->actor($actor->publicId);
        if (!\in_array($actorUrl, $target->alsoKnownAs, true)) {
            throw new \DomainException('The ActivityPub Move target does not prove the old actor in alsoKnownAs.');
        }

        return $this->transaction->run(function () use ($actor, $target, $urls, $timestamp): StoredActivityRepresentation {
            $publicId = $this->publicIdGenerator->generate();
            $document = $this->activityBuilder->moveActor(
                $publicId,
                $actor,
                $urls,
                $target->actorUrl,
                $timestamp,
            );
            $activity = $this->storeFollowersActivity(
                $publicId,
                $actor,
                'Move',
                'actor-move:' . $actor->publicId,
                $document,
                $timestamp,
            );
            $this->deliveryPlanner->plan($activity, $timestamp);
            if (!$this->localActorRepository->markMoved($actor->id, $target->actorUrl, $timestamp)) {
                throw new \RuntimeException('The local ActivityPub actor changed concurrently during Move.');
            }

            return $activity;
        });
    }

    private function assertPublishedLifecycle(): void
    {
        if (!\in_array($this->stateRepository->lifecycleState(), [
            FederationLifecycleState::ACTIVE,
            FederationLifecycleState::PAUSED,
        ], true)) {
            throw new \DomainException('ActivityPub identity changes require published federation.');
        }
    }

    private function activeLocalActor(int $actorId): LocalActor
    {
        $actor = $this->localActorRepository->findById($actorId);
        if (!$actor instanceof LocalActor) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if ($actor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        return $actor;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function storeFollowersActivity(
        string     $publicId,
        LocalActor $actor,
        string     $type,
        string     $deduplicationKey,
        array      $document,
        int        $timestamp,
    ): StoredActivityRepresentation {
        $serialized = $this->canonicalJson->encode($document);

        return $this->federationRepository->insertActivity(new NewStoredActivity(
            $publicId,
            $actor->id,
            null,
            $type,
            'public',
            ActivityDeliveryIntent::FOLLOWERS,
            $deduplicationKey,
            $serialized,
            hash('sha256', $serialized),
            $timestamp,
            $timestamp,
        ));
    }
}
