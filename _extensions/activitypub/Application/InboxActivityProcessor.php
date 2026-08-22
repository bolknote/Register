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
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\InboxState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\ModerationAction;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Infrastructure\ClaimedInboxItem;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\FollowRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\NotificationRepository;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;

final readonly class InboxActivityProcessor
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $actorRepository,
        private LocalFederationRepository     $federationRepository,
        private FollowRepository              $followRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private InboxInteractionProcessor      $interactionProcessor,
        private ModerationRuleRepository       $moderationRepository,
        private NotificationRepository         $notificationRepository,
        private RemoteActorRepository          $remoteActorRepository,
        private OutgoingFollowService           $outgoingFollowService,
    ) {
    }

    public function process(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        if (!hash_equals($item->activityUrl, $activity->id)
            || !hash_equals($item->actorUrl, $activity->actorUrl)
            || $item->activityType !== $activity->type
        ) {
            throw new \DomainException('The immutable ActivityPub inbox envelope no longer matches its activity.');
        }

        if ($this->moderationRepository->decision($remoteActor) === ModerationAction::BLOCK) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The verified actor is blocked by local moderation policy.');
        }

        return match ($activity->type) {
            'Follow'         => $this->follow($item, $activity, $remoteActor, $now),
            'Undo'           => $this->undo($item, $activity, $remoteActor, $now),
            'Accept'         => $this->followResponse($activity, $remoteActor, true, $now),
            'Reject'         => $this->followResponse($activity, $remoteActor, false, $now),
            'Block'          => $this->block($remoteActor, $now),
            'Create',
            'Update',
            'Delete',
            'Like',
            'EmojiReact',
            'Announce',
            'Flag',
            'Add',
            'Remove'         => $this->interactionProcessor->process($item, $activity, $remoteActor, $now),
            'Move'           => $this->move($activity, $remoteActor, $now),
            default          => new InboxProcessingResult(
                InboxState::IGNORED,
                'The verified ActivityPub activity type is unsupported and was safely ignored.',
            ),
        };
    }

    private function follow(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $objectUrl = $activity->objectIri();
        if ($objectUrl === null) {
            throw new \DomainException('An ActivityPub Follow must address one local actor.');
        }

        $localActor = $this->localActorFromUrl($objectUrl);
        if (!$localActor instanceof LocalActor) {
            throw new \DomainException('The ActivityPub Follow target is not an active local actor.');
        }

        if ($localActor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The ActivityPub Follow target is not an active local actor.');
        }

        if ($item->targetLocalActorId !== null && $item->targetLocalActorId !== $localActor->id) {
            throw new \DomainException('The ActivityPub Follow was posted to another local actor inbox.');
        }

        $autoAccept = $this->stateRepository->state()->autoAcceptFollows;
        $this->followRepository->recordIncoming(
            $localActor->id,
            $remoteActor->id,
            $activity->id,
            $autoAccept,
            $now,
        );
        if (!$autoAccept) {
            $this->notifyFollowRequest($localActor, $remoteActor, $activity, $now);

            return new InboxProcessingResult(InboxState::PROCESSED, 'The follow request is awaiting local moderation.');
        }

        $this->sendAccept($localActor, $remoteActor, $activity, $now);

        return new InboxProcessingResult(InboxState::PROCESSED, 'The follow request was accepted and queued for reply.');
    }

    private function undo(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $object = $activity->objectDocument();
        $objectType = $object['type'] ?? null;
        $objectActor = $object['actor'] ?? null;
        $objectId = $activity->objectIri();
        if ($objectId === null) {
            throw new \DomainException('An ActivityPub Undo must identify the original activity.');
        }

        if ($object !== null && $objectType !== 'Follow') {
            return $this->interactionProcessor->process($item, $activity, $remoteActor, $now);
        }

        if ($object === null
            && !$this->followRepository->hasIncomingByActivity($remoteActor->id, $objectId, $item->targetLocalActorId)
        ) {
            return $this->interactionProcessor->process($item, $activity, $remoteActor, $now);
        }

        if ($object !== null && (!\is_string($objectActor) || !hash_equals($activity->actorUrl, $objectActor))) {
            throw new \DomainException('The ActivityPub Undo Follow actor does not own the original activity.');
        }

        $ended = $this->followRepository->endIncomingByActivity(
            $remoteActor->id,
            $objectId,
            $item->targetLocalActorId,
            $now,
        );

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            $ended ? 'The incoming follow was ended.' : 'The incoming follow had already ended.',
        );
    }

    private function followResponse(
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        bool             $accepted,
        int              $now,
    ): InboxProcessingResult {
        $objectUrl = $activity->objectIri();
        $localActivity = $objectUrl === null ? null : $this->localActivityFromUrl($objectUrl);
        if (!$localActivity instanceof \Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation) {
            throw new \DomainException('The ActivityPub follow response does not reference a local Follow activity.');
        }

        if ($localActivity->type !== 'Follow') {
            throw new \DomainException('The ActivityPub follow response does not reference a local Follow activity.');
        }

        $changed = $this->followRepository->recordOutgoingResponse(
            $remoteActor->id,
            $localActivity->id,
            $accepted,
            $now,
        );

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            $changed ? 'The outgoing follow state was updated.' : 'The outgoing follow response was already applied.',
        );
    }

    private function block(RemoteActor $remoteActor, int $now): InboxProcessingResult
    {
        $ended = $this->followRepository->endAllWithRemote($remoteActor->id, $now);

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            'The remote Block ended ' . $ended . ' follow relationship(s).',
        );
    }

    private function move(
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $objectUrl = $activity->objectIri();
        $targetUrl = $activity->targetIri();
        if ($objectUrl === null
            || !hash_equals($activity->actorUrl, $objectUrl)
            || $targetUrl === null
            || hash_equals($remoteActor->actorUrl, $targetUrl)
        ) {
            throw new \DomainException('An ActivityPub account Move must move the signing actor to another actor.');
        }

        $target = $this->remoteActorRepository->findByUrl($targetUrl);
        if (!$target instanceof RemoteActor) {
            throw new \DomainException('The ActivityPub Move target does not prove the signing actor in alsoKnownAs.');
        }

        if ($target->state !== 'active'
            || !$target->cacheIsFresh($now)
            || !\in_array($remoteActor->actorUrl, $target->alsoKnownAs, true)
        ) {
            throw new \DomainException('The ActivityPub Move target does not prove the signing actor in alsoKnownAs.');
        }

        if ($this->moderationRepository->decision($target) === ModerationAction::BLOCK) {
            throw new \DomainException('The ActivityPub Move target is blocked by local moderation policy.');
        }

        if ($remoteActor->state === 'moved') {
            if (!hash_equals($remoteActor->movedToUrl ?? '', $targetUrl)) {
                throw new \DomainException('The remote ActivityPub actor already moved to another target.');
            }

            return new InboxProcessingResult(InboxState::PROCESSED, 'The verified remote actor Move was already applied.');
        }

        if ($remoteActor->state !== 'active') {
            throw new \DomainException('Only an active remote ActivityPub actor can initiate Move.');
        }

        $outgoingMigrated = 0;
        foreach ($this->followRepository->acceptedOutgoingLocalActorIds($remoteActor->id) as $localActorId) {
            $localActor = $this->actorRepository->findById($localActorId);
            if ($localActor instanceof LocalActor && $localActor->state === LocalActorState::ACTIVE) {
                $this->outgoingFollowService->follow($localActorId, $target->id, $now);
                ++$outgoingMigrated;
            }
        }

        $incomingMigrated = $this->followRepository->migrateIncomingRemoteActor(
            $remoteActor->id,
            $target->id,
            $now,
        );
        $this->followRepository->endAllWithRemote($remoteActor->id, $now);
        if (!$this->remoteActorRepository->markMoved($remoteActor->id, $target->actorUrl, $now)) {
            throw new \RuntimeException('The remote ActivityPub actor changed concurrently during Move.');
        }

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            'The verified remote actor Move migrated ' . $outgoingMigrated . ' outgoing and '
                . $incomingMigrated . ' incoming follow relationship(s).',
        );
    }

    private function sendAccept(
        LocalActor       $localActor,
        RemoteActor      $remoteActor,
        IncomingActivity $follow,
        int              $now,
    ): void {
        $publicId = $this->publicIdGenerator->generate();
        $document = $this->activityBuilder->accept(
            $publicId,
            $localActor,
            $this->urlGeneratorFactory->create(),
            $follow->document,
            $remoteActor->actorUrl,
            $now,
        );
        $serialized = $this->canonicalJson->encode($document);
        $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
            $publicId,
            $localActor->id,
            null,
            'Accept',
            'direct',
            ActivityDeliveryIntent::DIRECT,
            'accept-follow:' . hash('sha256', $follow->id),
            $serialized,
            hash('sha256', $serialized),
            $now,
            $now,
        ));
        $this->deliveryPlanner->planDirect(
            $activity,
            $remoteActor->inboxUrl,
            $remoteActor->actorUrl,
            $now,
        );
    }

    private function notifyFollowRequest(
        LocalActor       $localActor,
        RemoteActor      $remoteActor,
        IncomingActivity $activity,
        int              $now,
    ): void {
        $this->notificationRepository->create(
            $localActor->id,
            'follow_request',
            'remote_actor',
            $remoteActor->id,
            ['activity' => $activity->id, 'actor' => $remoteActor->actorUrl],
            $now,
        );
    }

    private function localActorFromUrl(string $url): ?LocalActor
    {
        $prefix = $this->urlGeneratorFactory->create()->resource('/activitypub/actors/');
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $publicId = substr($url, \strlen($prefix));
        if (str_contains($publicId, '/')) {
            return null;
        }

        return $this->actorRepository->findByPublicId($publicId);
    }

    private function localActivityFromUrl(string $url): ?\Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation
    {
        $prefix = $this->urlGeneratorFactory->create()->resource('/activitypub/activities/');
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $publicId = substr($url, \strlen($prefix));
        if (str_contains($publicId, '/')) {
            return null;
        }

        return $this->federationRepository->findActivity($publicId);
    }
}
