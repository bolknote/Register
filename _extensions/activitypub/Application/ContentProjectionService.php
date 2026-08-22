<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Content\ContentDetails;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentId;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\ContentProjectionAction;
use Register\Extension\activitypub\Domain\ContentProjectionMode;
use Register\Extension\activitypub\Domain\ContentFederationSettings;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationState;
use Register\Extension\activitypub\Domain\FederationUrlGenerator;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\MentionDeliveryPlanner;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\ContentFederationSettingsRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\NewStoredObject;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\ContentObjectDocumentBuilder;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;

/**
 * Projects Register's canonical publication state into immutable ActivityPub history.
 *
 * The caller may already own the editorial transaction. The savepoint wrapper preserves the
 * all-or-nothing boundary without performing remote I/O.
 */
final readonly class ContentProjectionService
{
    public function __construct(
        private ContentDetailsRepository       $contentRepository,
        private FederationStateRepository      $stateRepository,
        private ContentFederationSettingsRepository $settingsRepository,
        private LocalActorRepository            $actorRepository,
        private ContentActorResolver             $actorResolver,
        private LocalFederationRepository       $federationRepository,
        private FederationUrlGeneratorFactory   $urlGeneratorFactory,
        private PublicIdGenerator               $publicIdGenerator,
        private PortableDatabaseTransaction     $transaction,
        private ContentObjectDocumentBuilder    $objectBuilder,
        private LocalActivityDocumentBuilder    $activityBuilder,
        private CanonicalJson                    $json,
        private DeliveryPlanner                  $deliveryPlanner,
        private MentionDeliveryPlanner           $mentionDeliveryPlanner,
    ) {
    }

    public function synchronize(
        ContentId            $contentId,
        ContentProjectionMode $mode = ContentProjectionMode::LIVE_CHANGE,
        ?int                 $now = null,
    ): ContentProjectionResult {
        $timestamp = $now ?? time();
        if ($timestamp < 1) {
            throw new \InvalidArgumentException('The ActivityPub projection timestamp must be positive.');
        }

        return $this->transaction->run(
            fn(): ContentProjectionResult => $this->synchronizeInTransaction($contentId, $mode, $timestamp),
        );
    }

    private function synchronizeInTransaction(
        ContentId             $contentId,
        ContentProjectionMode $mode,
        int                   $now,
    ): ContentProjectionResult {
        $state = $this->stateRepository->state();
        if (in_array($state->lifecycle, [FederationLifecycleState::INSTALLED, FederationLifecycleState::DECOMMISSIONING, FederationLifecycleState::DECOMMISSIONED], true)
        ) {
            return new ContentProjectionResult(ContentProjectionAction::SKIPPED);
        }

        $details = $this->contentRepository->find($contentId);
        $settings = $this->settingsRepository->find($contentId);
        if (!$settings->isEnabled($state)) {
            $details = null;
        }

        $current = $this->federationRepository->findLiveObject($contentId);
        if (!$details instanceof ContentDetails) {
            return $current instanceof StoredObjectRepresentation
                ? $this->delete($current, $mode, $now)
                : new ContentProjectionResult(ContentProjectionAction::UNCHANGED);
        }

        $owner = $this->actorResolver->ownerFor($details->content->authorId);
        $urls  = $this->urlGeneratorFactory->create();
        if ($current instanceof StoredObjectRepresentation && $current->ownerActorId !== $owner->id) {
            $deletion = $this->delete($current, $mode, $now);
            $creation = $this->create($details, $state, $settings, $owner, $urls, $mode, $now);

            return new ContentProjectionResult(
                ContentProjectionAction::REPLACED,
                $creation->object,
                [...$deletion->activities, ...$creation->activities],
            );
        }

        if (!$current instanceof StoredObjectRepresentation) {
            return $this->create($details, $state, $settings, $owner, $urls, $mode, $now);
        }

        return $this->update($details, $state, $settings, $owner, $urls, $current, $mode, $now);
    }

    private function create(
        ContentDetails        $details,
        FederationState       $state,
        ContentFederationSettings $settings,
        LocalActor            $owner,
        FederationUrlGenerator $urls,
        ContentProjectionMode $mode,
        int                   $now,
    ): ContentProjectionResult {
        $content      = $details->content;
        $publicId     = $this->publicIdGenerator->generate();
        $objectType   = $settings->resolvesObjectType($state);
        $publishedAt  = $content->publishedAt !== null && $content->publishedAt > 0
            ? $content->publishedAt
            : ($content->updatedAt !== null && $content->updatedAt > 0 ? $content->updatedAt : $now);
        $updatedAt    = max($publishedAt, $content->updatedAt ?? 0);
        $visibility   = $settings->resolvesVisibility($state);
        $collective   = $this->actorResolver->collectiveFor($owner);
        $additionalFollowers = $this->actorResolver->additionalFollowerCollections($owner, $urls);
        $document     = $this->objectBuilder->build(
            $details,
            $owner,
            $urls,
            $publicId,
            $objectType,
            $visibility,
            $settings->resolvesDeliveryMode($state),
            $publishedAt,
            $updatedAt,
            $additionalFollowers,
            $settings->summary,
            $settings->language,
        );
        $snapshotJson = $this->json->encode($document);
        $snapshotHash = hash('sha256', $snapshotJson);
        $incarnation  = $this->federationRepository->nextIncarnation($content->id);
        $deliveryIntent = $mode->deliveryIntent($settings->suppressesPageDelivery($state));
        $object       = $this->federationRepository->insertObject(new NewStoredObject(
            $publicId,
            $content->id,
            $incarnation,
            $owner->id,
            $objectType,
            $visibility,
            $urls->resource($content->path),
            $snapshotJson,
            $snapshotHash,
            $publishedAt,
            $updatedAt,
            $now,
            $deliveryIntent === ActivityDeliveryIntent::FOLLOWERS ? $now : null,
            $content->featured ? $now : null,
        ));

        $activityAt = $mode === ContentProjectionMode::HISTORY_ONLY ? $publishedAt : max($now, $publishedAt);
        $activity   = $this->storeChangeActivity(
            'Create',
            $object,
            $owner,
            $urls,
            $document,
            $deliveryIntent,
            $activityAt,
            $now,
            [$owner->id],
        );

        $activities = [$activity];
        if ($collective instanceof LocalActor) {
            $activities[] = $this->storeCollectiveAnnounce(
                $object,
                $owner,
                $collective,
                $urls,
                $deliveryIntent,
                $activityAt,
                $now,
            );
        }

        if ($content->featured) {
            $activities[] = $this->storeFeaturedChange(
                'Add',
                $object,
                $owner,
                $urls,
                $deliveryIntent,
                $activityAt,
                $now,
            );
        }

        return new ContentProjectionResult(ContentProjectionAction::CREATED, $object, $activities);
    }

    private function update(
        ContentDetails             $details,
        FederationState            $state,
        ContentFederationSettings  $settings,
        LocalActor                 $owner,
        FederationUrlGenerator     $urls,
        StoredObjectRepresentation $current,
        ContentProjectionMode      $mode,
        int                        $now,
    ): ContentProjectionResult {
        $content   = $details->content;
        $additionalFollowers = $this->actorResolver->additionalFollowerCollections($owner, $urls);
        $updatedAt = max($current->updatedAt, $content->updatedAt ?? 0, $current->publishedAt);
        $visibility = $settings->resolvesVisibility($state);
        $document  = $this->objectBuilder->build(
            $details,
            $owner,
            $urls,
            $current->publicId,
            $current->objectType,
            $visibility,
            $settings->resolvesDeliveryMode($state),
            $current->publishedAt,
            $updatedAt,
            $additionalFollowers,
            $settings->summary,
            $settings->language,
        );
        $snapshotJson = $this->json->encode($document);
        $snapshotHash = hash('sha256', $snapshotJson);
        $deliveryIntent = $mode->deliveryIntent(
            $settings->suppressesPageDelivery($state) && $current->broadcastAt === null,
        );
        $startingBroadcast = $deliveryIntent === ActivityDeliveryIntent::FOLLOWERS
            && $current->broadcastAt === null;
        $snapshotChanged = !hash_equals($current->snapshotHash, $snapshotHash);
        $featureChanged = ($current->featuredAt !== null) !== $content->featured;
        if (!$snapshotChanged && !$startingBroadcast && !$featureChanged) {
            return new ContentProjectionResult(ContentProjectionAction::UNCHANGED, $current);
        }

        if ($snapshotChanged && $updatedAt <= $current->updatedAt) {
            $updatedAt = max($now, $current->updatedAt + 1);
            $document  = $this->objectBuilder->build(
                $details,
                $owner,
                $urls,
                $current->publicId,
                $current->objectType,
                $visibility,
                $settings->resolvesDeliveryMode($state),
                $current->publishedAt,
                $updatedAt,
                $additionalFollowers,
                $settings->summary,
                $settings->language,
            );
            $snapshotJson = $this->json->encode($document);
            $snapshotHash = hash('sha256', $snapshotJson);
        }

        $object = $current;
        if ($snapshotChanged) {
            $object = $this->federationRepository->updateObject(
                $current,
                $urls->resource($content->path),
                $snapshotJson,
                $snapshotHash,
                $updatedAt,
                $visibility,
                $startingBroadcast ? $now : $current->broadcastAt,
            );
        } elseif ($startingBroadcast) {
            $object = $this->federationRepository->markObjectBroadcastStarted($current, $now);
        }

        if ($featureChanged) {
            $object = $this->federationRepository->setObjectFeatured($object, $content->featured, $now);
        }

        $activities = [];
        if ($snapshotChanged || $startingBroadcast) {
            $activities[] = $this->storeChangeActivity(
                $startingBroadcast ? 'Create' : 'Update',
                $object,
                $owner,
                $urls,
                $document,
                $deliveryIntent,
                $now,
                $now,
                $this->actorResolver->followerActorIds($owner),
                $startingBroadcast ? 'broadcast-create' : null,
                $startingBroadcast ? null : $this->snapshotDocument($current),
            );
        }

        $collective = $startingBroadcast ? $this->actorResolver->collectiveFor($owner) : null;
        if ($collective instanceof LocalActor) {
            $activities[] = $this->storeCollectiveAnnounce(
                $object,
                $owner,
                $collective,
                $urls,
                $deliveryIntent,
                $now,
                $now,
                'collective-broadcast-announce',
            );
        }

        $featureType = null;
        if ($content->featured && ($featureChanged || $startingBroadcast)) {
            $featureType = 'Add';
        } elseif (!$content->featured && $featureChanged && $current->broadcastAt !== null) {
            $featureType = 'Remove';
        }

        if ($featureType !== null) {
            $activities[] = $this->storeFeaturedChange(
                $featureType,
                $object,
                $owner,
                $urls,
                $deliveryIntent,
                $now,
                $now,
            );
        }

        return new ContentProjectionResult(ContentProjectionAction::UPDATED, $object, $activities);
    }

    private function delete(
        StoredObjectRepresentation $current,
        ContentProjectionMode      $mode,
        int                        $now,
    ): ContentProjectionResult {
        $owner = $this->actorRepository->findById($current->ownerActorId);
        if (!$owner instanceof LocalActor) {
            throw new \RuntimeException('A live ActivityPub object has no active owning actor.');
        }

        if ($owner->state !== LocalActorState::ACTIVE) {
            throw new \RuntimeException('A live ActivityPub object has no active owning actor.');
        }

        $urls          = $this->urlGeneratorFactory->create();
        $additionalFollowers = $this->actorResolver->additionalFollowerCollections($owner, $urls);
        $previousDocument = $this->snapshotDocument($current);
        $activityId    = $this->publicIdGenerator->generate();
        $document      = $this->activityBuilder->delete(
            $activityId,
            $owner,
            $urls,
            $current,
            $now,
            $additionalFollowers,
            $this->mentionDeliveryPlanner->recipients([$previousDocument]),
        );
        $serialized    = $this->json->encode($document);
        $deduplication = $this->deduplicationKey($current, 'delete');
        $tombstone     = $this->federationRepository->tombstoneObject($current, $now);
        $activity      = $this->federationRepository->insertActivity(new NewStoredActivity(
            $activityId,
            $owner->id,
            $current->id,
            'Delete',
            $current->visibility,
            $mode->deliveryIntent($current->broadcastAt === null),
            $deduplication,
            $serialized,
            hash('sha256', $serialized),
            $now,
            $now,
        ));
        $this->deliveryPlanner->planForActors($activity, $this->actorResolver->followerActorIds($owner), $now);
        $this->mentionDeliveryPlanner->plan($activity, [$previousDocument], $now);

        return new ContentProjectionResult(ContentProjectionAction::TOMBSTONED, $tombstone, [$activity]);
    }

    /**
     * @param array<string, mixed> $objectDocument
     * @param non-empty-list<int> $followerActorIds
     * @param array<string, mixed>|null $previousObjectDocument
     */
    private function storeChangeActivity(
        string                     $type,
        StoredObjectRepresentation $object,
        LocalActor                 $owner,
        FederationUrlGenerator     $urls,
        array                      $objectDocument,
        ActivityDeliveryIntent     $deliveryIntent,
        int                        $publishedAt,
        int                        $createdAt,
        array                      $followerActorIds,
        ?string                    $deduplicationPurpose = null,
        ?array                     $previousObjectDocument = null,
    ): StoredActivityRepresentation {
        $mentionDocuments = $previousObjectDocument === null
            ? [$objectDocument]
            : [$previousObjectDocument, $objectDocument];
        $activityId = $this->publicIdGenerator->generate();
        $document   = $this->activityBuilder->change(
            $type,
            $activityId,
            $owner,
            $urls,
            $objectDocument,
            $object->visibility,
            $publishedAt,
            $this->additionalFollowerCollectionsForIds($owner, $followerActorIds, $urls),
            $this->mentionDeliveryPlanner->recipients($mentionDocuments),
        );
        $serialized = $this->json->encode($document);
        $key = $this->deduplicationKey(
            $object,
            ($deduplicationPurpose ?? strtolower($type)) . ':' . $object->snapshotHash,
        );

        $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
            $activityId,
            $owner->id,
            $object->id,
            $type,
            $object->visibility,
            $deliveryIntent,
            $key,
            $serialized,
            hash('sha256', $serialized),
            $publishedAt,
            $createdAt,
        ));
        $this->deliveryPlanner->planForActors($activity, $followerActorIds, $createdAt);
        $this->mentionDeliveryPlanner->plan($activity, $mentionDocuments, $createdAt);

        return $activity;
    }

    private function storeCollectiveAnnounce(
        StoredObjectRepresentation $object,
        LocalActor                 $owner,
        LocalActor                 $collective,
        FederationUrlGenerator     $urls,
        ActivityDeliveryIntent     $deliveryIntent,
        int                        $publishedAt,
        int                        $createdAt,
        string                     $deduplicationPurpose = 'collective-announce',
    ): StoredActivityRepresentation {
        $publicId = $this->publicIdGenerator->generate();
        $document = $this->activityBuilder->interaction(
            'Announce',
            $publicId,
            $collective,
            $urls,
            $urls->actor($owner->publicId),
            $urls->object($object->publicId),
            '',
            $publishedAt,
        );
        $serialized = $this->json->encode($document);
        $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
            $publicId,
            $collective->id,
            $object->id,
            'Announce',
            'public',
            $deliveryIntent,
            $this->deduplicationKey($object, $deduplicationPurpose),
            $serialized,
            hash('sha256', $serialized),
            $publishedAt,
            $createdAt,
        ));
        $this->deliveryPlanner->plan($activity, $createdAt);

        return $activity;
    }

    private function storeFeaturedChange(
        string                     $type,
        StoredObjectRepresentation $object,
        LocalActor                 $owner,
        FederationUrlGenerator     $urls,
        ActivityDeliveryIntent     $deliveryIntent,
        int                        $publishedAt,
        int                        $createdAt,
    ): StoredActivityRepresentation {
        $publicId = $this->publicIdGenerator->generate();
        $followerActorIds = $this->actorResolver->followerActorIds($owner);
        $document = $this->activityBuilder->featuredChange(
            $type,
            $publicId,
            $owner,
            $urls,
            $urls->object($object->publicId),
            $object->visibility,
            $publishedAt,
            $this->additionalFollowerCollectionsForIds($owner, $followerActorIds, $urls),
        );
        $serialized = $this->json->encode($document);
        $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
            $publicId,
            $owner->id,
            $object->id,
            $type,
            $object->visibility,
            $deliveryIntent,
            $this->deduplicationKey($object, 'featured-' . strtolower($type) . ':' . $publicId),
            $serialized,
            hash('sha256', $serialized),
            $publishedAt,
            $createdAt,
        ));
        $this->deliveryPlanner->planForActors($activity, $followerActorIds, $createdAt);

        return $activity;
    }

    /**
     * @param non-empty-list<int> $actorIds
     * @return list<string>
     */
    private function additionalFollowerCollectionsForIds(
        LocalActor             $owner,
        array                  $actorIds,
        FederationUrlGenerator $urls,
    ): array {
        $collections = [];
        foreach ($actorIds as $actorId) {
            if ($actorId === $owner->id) {
                continue;
            }

            $actor = $this->actorRepository->findById($actorId);
            if (!$actor instanceof LocalActor) {
                throw new \RuntimeException('An additional ActivityPub delivery actor is unavailable.');
            }

            if ($actor->state !== LocalActorState::ACTIVE) {
                throw new \RuntimeException('An additional ActivityPub delivery actor is unavailable.');
            }

            $collections[] = $urls->actorFollowers($actor->publicId);
        }

        return $collections;
    }

    private function deduplicationKey(StoredObjectRepresentation $object, string $suffix): string
    {
        return $object->contentId->type->value . ':' . $object->contentId->value . ':'
            . $object->incarnation . ':' . $suffix;
    }

    /** @return array<string, mixed> */
    private function snapshotDocument(StoredObjectRepresentation $object): array
    {
        try {
            $document = json_decode($object->snapshotJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored local ActivityPub object snapshot is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('A stored local ActivityPub object snapshot must be a JSON object.');
        }

        return $document;
    }
}
