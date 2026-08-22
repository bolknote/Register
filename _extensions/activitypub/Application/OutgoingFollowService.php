<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\ModerationAction;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\FollowRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;

/** Creates durable Follow/Undo activities and direct deliveries without remote I/O. */
final readonly class OutgoingFollowService
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $localActorRepository,
        private RemoteActorRepository         $remoteActorRepository,
        private FollowRepository              $followRepository,
        private ModerationRuleRepository      $moderationRepository,
        private LocalFederationRepository     $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private PortableDatabaseTransaction   $transaction,
    ) {
    }

    public function follow(int $localActorId, int $remoteActorId, ?int $now = null): StoredActivityRepresentation
    {
        $timestamp = $now ?? time();
        [$localActor, $remoteActor] = $this->actors($localActorId, $remoteActorId, $timestamp);

        return $this->transaction->run(function () use ($localActor, $remoteActor, $timestamp): StoredActivityRepresentation {
            $existing = $this->followRepository->findOutgoing($localActor->id, $remoteActor->id);
            if ($existing instanceof \Register\Extension\activitypub\Domain\FollowRelationship) {
                if ($existing->isCurrent() && $existing->localActivityId !== null) {
                    $activity = $this->federationRepository->findActivityById($existing->localActivityId);
                    if ($activity instanceof StoredActivityRepresentation) {
                        if ($activity->type === 'Follow') {
                            return $activity;
                        }
                    }

                    throw new \RuntimeException('The current outgoing Follow lost its immutable local activity.');
                }
            }

            $publicId  = $this->publicIdGenerator->generate();
            $urls      = $this->urlGeneratorFactory->create();
            $document  = $this->activityBuilder->follow($publicId, $localActor, $urls, $remoteActor->actorUrl, $timestamp);
            $serialized = $this->canonicalJson->encode($document);
            $activity  = $this->federationRepository->insertActivity(new NewStoredActivity(
                $publicId,
                $localActor->id,
                null,
                'Follow',
                'direct',
                ActivityDeliveryIntent::DIRECT,
                'follow:' . $publicId,
                $serialized,
                hash('sha256', $serialized),
                $timestamp,
                $timestamp,
            ));
            $this->followRepository->recordOutgoing(
                $localActor->id,
                $remoteActor->id,
                $urls->activity($publicId),
                $activity->id,
                $timestamp,
            );
            $this->deliveryPlanner->planDirect($activity, $remoteActor->inboxUrl, $remoteActor->actorUrl, $timestamp);

            return $activity;
        });
    }

    public function unfollow(int $localActorId, int $remoteActorId, ?int $now = null): ?StoredActivityRepresentation
    {
        $timestamp = $now ?? time();
        [$localActor, $remoteActor] = $this->actors($localActorId, $remoteActorId, $timestamp, allowBlocked: true);

        return $this->transaction->run(function () use ($localActor, $remoteActor, $timestamp): ?StoredActivityRepresentation {
            $relationship = $this->followRepository->findOutgoing($localActor->id, $remoteActor->id);
            if (!$relationship instanceof \Register\Extension\activitypub\Domain\FollowRelationship || !$relationship->isCurrent()) {
                return null;
            }

            if ($relationship->localActivityId === null) {
                throw new \RuntimeException('The outgoing Follow has no immutable local activity.');
            }

            $original = $this->federationRepository->findActivityById($relationship->localActivityId);
            if (!$original instanceof StoredActivityRepresentation) {
                throw new \RuntimeException('The outgoing Follow activity cannot be loaded for Undo.');
            }

            if ($original->type !== 'Follow') {
                throw new \RuntimeException('The outgoing Follow activity cannot be loaded for Undo.');
            }

            $originalDocument = $this->decode($original->serializedBody);
            $publicId  = $this->publicIdGenerator->generate();
            $document  = $this->activityBuilder->undo(
                $publicId,
                $localActor,
                $this->urlGeneratorFactory->create(),
                $originalDocument,
                $remoteActor->actorUrl,
                $timestamp,
            );
            $serialized = $this->canonicalJson->encode($document);
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $publicId,
                $localActor->id,
                null,
                'Undo',
                'direct',
                ActivityDeliveryIntent::DIRECT,
                'undo-follow:' . $publicId,
                $serialized,
                hash('sha256', $serialized),
                $timestamp,
                $timestamp,
            ));
            if (!$this->followRepository->endOutgoing($localActor->id, $remoteActor->id, $timestamp)) {
                throw new \RuntimeException('The outgoing Follow changed concurrently during Undo.');
            }

            $this->deliveryPlanner->planDirect($activity, $remoteActor->inboxUrl, $remoteActor->actorUrl, $timestamp);

            return $activity;
        });
    }

    /** @return array{LocalActor, RemoteActor} */
    private function actors(int $localActorId, int $remoteActorId, int $now, bool $allowBlocked = false): array
    {
        if ($now < 1 || $this->stateRepository->lifecycleState() !== FederationLifecycleState::ACTIVE) {
            throw new \DomainException('ActivityPub federation must be active for outgoing follows.');
        }

        $localActor  = $this->localActorRepository->findById($localActorId);
        $remoteActor = $this->remoteActorRepository->findById($remoteActorId);
        if (!$localActor instanceof LocalActor) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if ($localActor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if (!$remoteActor instanceof RemoteActor) {
            throw new \DomainException('The selected remote ActivityPub actor is unavailable.');
        }

        if (!\in_array($remoteActor->state, ['active', 'blocked'], true)) {
            throw new \DomainException('The selected remote ActivityPub actor is unavailable.');
        }

        if (!$allowBlocked && $this->moderationRepository->decision($remoteActor) === ModerationAction::BLOCK) {
            throw new \DomainException('The selected remote ActivityPub actor is blocked.');
        }

        return [$localActor, $remoteActor];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored outgoing Follow activity is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('A stored outgoing Follow activity must be a JSON object.');
        }

        return $document;
    }
}
