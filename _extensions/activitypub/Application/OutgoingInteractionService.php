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
use Register\Extension\activitypub\Domain\LocalInteraction;
use Register\Extension\activitypub\Domain\ModerationAction;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Domain\RemoteObject;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\LocalInteractionRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewLocalInteraction;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\RemoteObjectRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;

/** Durable Like, EmojiReact, Announce and exact Undo for private-reader objects. */
final readonly class OutgoingInteractionService
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $localActorRepository,
        private RemoteActorRepository         $remoteActorRepository,
        private RemoteObjectRepository        $remoteObjectRepository,
        private LocalInteractionRepository    $interactionRepository,
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

    public function create(
        int    $localActorId,
        int    $remoteObjectId,
        string $type,
        string $emoji = '',
        ?int   $now = null,
    ): LocalInteraction {
        $timestamp = $now ?? time();
        $type = $this->type($type, $emoji);
        [$localActor, $remoteObject, $remoteActor] = $this->target($localActorId, $remoteObjectId, $timestamp);

        return $this->transaction->run(function () use (
            $localActor,
            $remoteObject,
            $remoteActor,
            $type,
            $emoji,
            $timestamp,
        ): LocalInteraction {
            $existing = $this->interactionRepository->find(
                $localActor->id,
                $remoteObject->objectUrl,
                $type,
                $emoji,
            );
            if ($existing instanceof LocalInteraction) {
                if ($existing->state === 'active') {
                    return $existing;
                }
            }

            $activityType = match ($type) {
                'like'        => 'Like',
                'emoji_react' => 'EmojiReact',
                'announce'    => 'Announce',
                default       => throw new \LogicException('Unexpected local ActivityPub interaction type.'),
            };
            $publicId = $this->publicIdGenerator->generate();
            $document = $this->activityBuilder->interaction(
                $activityType,
                $publicId,
                $localActor,
                $this->urlGeneratorFactory->create(),
                $remoteActor->actorUrl,
                $remoteObject->objectUrl,
                $emoji,
                $timestamp,
            );
            $serialized = $this->canonicalJson->encode($document);
            $intent = $type === 'announce'
                ? ActivityDeliveryIntent::FOLLOWERS
                : ActivityDeliveryIntent::DIRECT;
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $publicId,
                $localActor->id,
                null,
                $activityType,
                $type === 'announce' ? 'public' : 'direct',
                $intent,
                'reader-interaction:' . $publicId,
                $serialized,
                hash('sha256', $serialized),
                $timestamp,
                $timestamp,
            ));
            $interaction = $this->interactionRepository->create(new NewLocalInteraction(
                $localActor->id,
                $remoteActor->id,
                $remoteObject->objectUrl,
                $type,
                $emoji,
                $activity->id,
                $timestamp,
            ));
            $this->deliver($activity, $remoteActor, $intent, $timestamp);

            return $interaction;
        });
    }

    public function undo(
        int    $localActorId,
        int    $remoteObjectId,
        string $type,
        string $emoji = '',
        ?int   $now = null,
    ): ?LocalInteraction {
        $timestamp = $now ?? time();
        $type = $this->type($type, $emoji);
        [$localActor, $remoteObject, $remoteActor] = $this->target($localActorId, $remoteObjectId, $timestamp, true);

        return $this->transaction->run(function () use (
            $localActor,
            $remoteObject,
            $remoteActor,
            $type,
            $emoji,
            $timestamp,
        ): ?LocalInteraction {
            $interaction = $this->interactionRepository->find(
                $localActor->id,
                $remoteObject->objectUrl,
                $type,
                $emoji,
            );
            if (!$interaction instanceof LocalInteraction) {
                return null;
            }

            if ($interaction->state !== 'active') {
                return $interaction;
            }

            $original = $this->federationRepository->findActivityById($interaction->localActivityId);
            if (!$original instanceof StoredActivityRepresentation) {
                throw new \RuntimeException('The local ActivityPub interaction lost its immutable activity.');
            }

            $publicId = $this->publicIdGenerator->generate();
            $document = $this->activityBuilder->undo(
                $publicId,
                $localActor,
                $this->urlGeneratorFactory->create(),
                $this->decode($original->serializedBody),
                $remoteActor->actorUrl,
                $timestamp,
            );
            $serialized = $this->canonicalJson->encode($document);
            $intent = $type === 'announce'
                ? ActivityDeliveryIntent::FOLLOWERS
                : ActivityDeliveryIntent::DIRECT;
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $publicId,
                $localActor->id,
                null,
                'Undo',
                $type === 'announce' ? 'public' : 'direct',
                $intent,
                'undo-reader-interaction:' . $publicId,
                $serialized,
                hash('sha256', $serialized),
                $timestamp,
                $timestamp,
            ));
            $ended = $this->interactionRepository->end($interaction, $activity->id, $timestamp);
            $this->deliver($activity, $remoteActor, $intent, $timestamp);

            return $ended;
        });
    }

    private function deliver(
        StoredActivityRepresentation $activity,
        RemoteActor                  $remoteActor,
        ActivityDeliveryIntent       $intent,
        int                          $now,
    ): void {
        if ($intent === ActivityDeliveryIntent::FOLLOWERS) {
            $this->deliveryPlanner->plan($activity, $now);
        }

        $this->deliveryPlanner->planDirect($activity, $remoteActor->inboxUrl, $remoteActor->actorUrl, $now);
    }

    /** @return array{LocalActor, RemoteObject, RemoteActor} */
    private function target(int $localActorId, int $remoteObjectId, int $now, bool $allowBlocked = false): array
    {
        if ($now < 1 || $this->stateRepository->lifecycleState() !== FederationLifecycleState::ACTIVE) {
            throw new \DomainException('ActivityPub federation must be active for reader interactions.');
        }

        $localActor   = $this->localActorRepository->findById($localActorId);
        $remoteObject = $this->remoteObjectRepository->findById($remoteObjectId);
        if (!$localActor instanceof LocalActor) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if ($localActor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if (!$remoteObject instanceof RemoteObject
            || !$this->remoteObjectRepository->isVisibleToLocalActor($remoteObject, $localActor->id)
        ) {
            throw new \DomainException('The remote ActivityPub object is unavailable to the selected local actor.');
        }

        $remoteActor = $this->remoteActorRepository->findById($remoteObject->ownerActorId);
        if (!$remoteActor instanceof RemoteActor) {
            throw new \DomainException('The remote ActivityPub object owner is unavailable.');
        }

        if (!\in_array($remoteActor->state, ['active', 'blocked'], true)) {
            throw new \DomainException('The remote ActivityPub object owner is unavailable.');
        }

        if (!$allowBlocked && $this->moderationRepository->decision($remoteActor) === ModerationAction::BLOCK) {
            throw new \DomainException('The remote ActivityPub object owner is blocked.');
        }

        return [$localActor, $remoteObject, $remoteActor];
    }

    private function type(string $type, string $emoji): string
    {
        if (!\in_array($type, ['like', 'emoji_react', 'announce'], true)
            || mb_strlen($emoji) > 64
            || ($type === 'emoji_react' && trim($emoji) === '')
            || ($type !== 'emoji_react' && $emoji !== '')
        ) {
            throw new \InvalidArgumentException('The local ActivityPub reader interaction is invalid.');
        }

        return $type;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored local ActivityPub interaction is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('A stored local ActivityPub interaction must be a JSON object.');
        }

        return $document;
    }
}
