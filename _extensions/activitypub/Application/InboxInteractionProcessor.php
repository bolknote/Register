<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Comment\CommentImport;
use Register\Comment\CommentImportService;
use Register\Content\ContentType;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Module\Reactions\ReactionAggregateTargetType;
use Register\Extension\activitypub\Domain\InboxState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\ModerationAction;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Domain\RemoteInteraction;
use Register\Extension\activitypub\Domain\RemoteObject;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Inbox\RemoteObjectDocumentValidator;
use Register\Extension\activitypub\Infrastructure\ClaimedInboxItem;
use Register\Extension\activitypub\Infrastructure\FollowRepository;
use Register\Extension\activitypub\Infrastructure\InteractionRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewRemoteInteraction;
use Register\Extension\activitypub\Infrastructure\NotificationRepository;
use Register\Extension\activitypub\Infrastructure\RemoteObjectRepository;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use Register\Extension\activitypub\Infrastructure\ValidatedRemoteObject;
use Register\Extension\activitypub\Presentation\RemoteCommentTextFormatter;

/** Applies signed social activities through public Register integration boundaries. */
final readonly class InboxInteractionProcessor
{
    public function __construct(
        private RemoteObjectDocumentValidator $objectValidator,
        private RemoteObjectRepository        $objectRepository,
        private InteractionRepository         $interactionRepository,
        private LocalFederationRepository     $federationRepository,
        private LocalActorRepository          $actorRepository,
        private FollowRepository              $followRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private CommentImportService          $commentImportService,
        private RemoteCommentTextFormatter    $commentFormatter,
        private ReactionAggregateRepository   $reactionRepository,
        private ModerationRuleRepository      $moderationRepository,
        private NotificationRepository        $notificationRepository,
    ) {
    }

    public function process(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $decision = $this->moderationRepository->decision($remoteActor);
        if ($decision === ModerationAction::BLOCK) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The verified actor is blocked by local moderation policy.');
        }

        return match ($activity->type) {
            'Create'     => $this->create($item, $activity, $remoteActor, $decision, $now),
            'Update'     => $this->update($item, $activity, $remoteActor, $now),
            'Delete'     => $this->delete($activity, $remoteActor, $now),
            'Like'       => $this->reaction($item, $activity, $remoteActor, $decision, 'like', '', $now),
            'EmojiReact' => $this->reaction(
                $item,
                $activity,
                $remoteActor,
                $decision,
                '',
                $this->emoji($activity),
                $now,
            ),
            'Announce'   => $this->reaction($item, $activity, $remoteActor, $decision, '', '🔁', $now),
            'Add',
            'Remove'     => $this->featured($activity, $remoteActor, $now),
            'Undo'       => $this->undo($activity, $remoteActor, $now),
            'Flag'       => $this->flag($item, $activity, $remoteActor, $decision, $now),
            default      => new InboxProcessingResult(
                InboxState::IGNORED,
                'The verified activity is unsupported by the interaction processor.',
            ),
        };
    }

    private function create(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        ModerationAction $decision,
        int              $now,
    ): InboxProcessingResult {
        $document = $activity->objectDocument();
        if ($document === null) {
            throw new \DomainException('Register requires an embedded object in an incoming ActivityPub Create.');
        }

        $object = $this->objectValidator->validate($document, $remoteActor->actorUrl, $now);
        $existingInteraction = $this->interactionRepository->findByActivityUrl($activity->id);
        if ($existingInteraction instanceof RemoteInteraction) {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The remote ActivityPub Create was already applied.');
        }

        $replyTarget = $object->inReplyToUrl === null ? null : $this->replyTarget($object->inReplyToUrl);
        $recipients  = $this->localRecipients($item, $object, $remoteActor, $replyTarget);
        if ($object->visibility === 'direct' && $recipients === []) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The direct ActivityPub object addresses no active local actor.');
        }

        if ($replyTarget === null && $recipients === []) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The remote ActivityPub object is not relevant to a local reader.');
        }

        $storedObject = $this->objectRepository->create($object, $remoteActor->id, $recipients, $now);
        if ($replyTarget instanceof LocalNoteReplyTarget) {
            return $this->storeLocalNoteReply(
                $activity,
                $remoteActor,
                $object,
                $storedObject,
                $replyTarget->note,
                $decision,
                $now,
            );
        }

        if ($replyTarget instanceof ContentReplyTarget
            && \in_array($object->visibility, ['public', 'unlisted'], true)
        ) {
            return $this->importReply($activity, $remoteActor, $object, $storedObject, $replyTarget, $decision, $now);
        }

        if ($object->visibility === 'direct') {
            $interaction = $this->interactionRepository->create(new NewRemoteInteraction(
                'direct_note',
                $remoteActor->id,
                $activity->id,
                $object->objectUrl,
                $replyTarget instanceof ContentReplyTarget ? $replyTarget->object->id : null,
                null,
                '',
                '',
                $this->provenance($activity, $remoteActor, $object),
                $now,
            ));
            if ($decision !== ModerationAction::SILENCE) {
                $this->notifyRecipients($recipients, 'private_note', $interaction, $activity, $remoteActor, $now);
            }

            return new InboxProcessingResult(
                InboxState::PROCESSED,
                'The directly addressed Note was stored in the private, unencrypted federation inbox.',
            );
        }

        return new InboxProcessingResult(InboxState::PROCESSED, 'The remote object was stored for the private reader.');
    }

    private function update(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $document = $activity->objectDocument();
        if ($document === null) {
            throw new \DomainException('Register requires an embedded object in an incoming ActivityPub Update.');
        }

        $object   = $this->objectValidator->validate($document, $remoteActor->actorUrl, $now);
        $existing = $this->objectRepository->findByUrl($object->objectUrl);
        if (!$existing instanceof RemoteObject) {
            throw new \DomainException('The ActivityPub Update references an unknown remote object.');
        }

        $replyTarget = $object->inReplyToUrl === null ? null : $this->replyTarget($object->inReplyToUrl);
        $recipients  = $this->localRecipients($item, $object, $remoteActor, $replyTarget);
        if ($recipients === []) {
            $recipients = $this->objectRepository->recipients($existing);
        }

        $this->objectRepository->update($object, $remoteActor->id, $recipients, $now);
        $interaction = $this->interactionRepository->findActiveByObjectUrl($object->objectUrl);
        if ($interaction instanceof RemoteInteraction
            && $interaction->type === 'reply'
            && $interaction->localCommentId !== null
            && $interaction->localObjectId !== null
        ) {
            $localObject = $this->federationRepository->findObjectById($interaction->localObjectId);
            if (!$localObject instanceof StoredObjectRepresentation) {
                throw new \RuntimeException('A remote reply lost its local ActivityPub object target.');
            }

            $this->commentImportService->update(
                $interaction->localCommentId,
                $localObject->contentId,
                $this->commentFormatter->format($object->contentHtml),
            );
        }

        return new InboxProcessingResult(InboxState::PROCESSED, 'The owned remote ActivityPub object was updated.');
    }

    private function delete(IncomingActivity $activity, RemoteActor $remoteActor, int $now): InboxProcessingResult
    {
        $objectUrl = $activity->objectIri();
        if ($objectUrl === null) {
            throw new \DomainException('An ActivityPub Delete must identify its remote object.');
        }

        $deleted = $this->objectRepository->delete($objectUrl, $remoteActor->id, $now);
        if (!$deleted instanceof RemoteObject) {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The unknown remote object was already absent.');
        }

        $interaction = $this->interactionRepository->findActiveByObjectUrl($objectUrl);
        if ($interaction instanceof RemoteInteraction) {
            if ($interaction->type === 'reply'
                && $interaction->localCommentId !== null
                && $interaction->localObjectId !== null
            ) {
                $localObject = $this->federationRepository->findObjectById($interaction->localObjectId);
                if ($localObject instanceof StoredObjectRepresentation) {
                    $this->commentImportService->tombstone($interaction->localCommentId, $localObject->contentId);
                }
            }

            $this->interactionRepository->end($interaction->remoteActivityUrl, $remoteActor->id, 'deleted', $now);
        }

        return new InboxProcessingResult(InboxState::PROCESSED, 'The owned remote ActivityPub object was tombstoned locally.');
    }

    private function featured(
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        int              $now,
    ): InboxProcessingResult {
        $objectUrl = $activity->objectIri();
        $targetUrl = $activity->targetIri();
        if ($objectUrl === null || $targetUrl === null) {
            throw new \DomainException('An ActivityPub featured change must identify its object and target collection.');
        }

        if ($remoteActor->featuredUrl === null || !hash_equals($remoteActor->featuredUrl, $targetUrl)) {
            throw new \DomainException("The ActivityPub featured change does not target the signing actor's advertised collection.");
        }

        $object = $this->objectRepository->findByUrl($objectUrl);
        if (!$object instanceof RemoteObject) {
            return new InboxProcessingResult(
                InboxState::IGNORED,
                'The remote featured change references an object that is not present in the private reader.',
            );
        }

        $featured = $activity->type === 'Add';
        $this->objectRepository->setFeatured($object, $remoteActor->id, $featured, $now);

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            $featured
                ? 'The owned remote object was added to its advertised featured collection.'
                : 'The owned remote object was removed from its advertised featured collection.',
        );
    }

    private function importReply(
        IncomingActivity      $activity,
        RemoteActor           $remoteActor,
        ValidatedRemoteObject $object,
        RemoteObject          $storedObject,
        ContentReplyTarget    $target,
        ModerationAction      $decision,
        int                   $now,
    ): InboxProcessingResult {
        $localObject = $target->object;
        $commentId = $this->commentImportService->import(new CommentImport(
            $localObject->contentId,
            mb_substr($remoteActor->displayName !== '' ? $remoteActor->displayName : $remoteActor->preferredUsername, 0, 50),
            $this->commentFormatter->format($object->contentHtml),
            $target->parentCommentId,
            $object->publishedAt,
        ));
        $interaction = $this->interactionRepository->create(new NewRemoteInteraction(
            'reply',
            $remoteActor->id,
            $activity->id,
            $storedObject->objectUrl,
            $localObject->id,
            $commentId,
            '',
            '',
            $this->provenance($activity, $remoteActor, $object),
            $now,
        ));
        if ($decision === ModerationAction::TRUST) {
            $this->commentImportService->publish($commentId, $localObject->contentId);
            $this->interactionRepository->setReplyPublicByComment($commentId, true, $now);
        }

        if ($decision !== ModerationAction::SILENCE) {
            $this->notificationRepository->create(
                $localObject->ownerActorId,
                $decision === ModerationAction::TRUST ? 'reply' : 'moderation_reply',
                'interaction',
                $interaction->id,
                [
                    'activity' => $activity->id,
                    'actor'    => $remoteActor->actorUrl,
                    'object'   => $object->objectUrl,
                    'comment'  => $commentId,
                ],
                $now,
            );
        }

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            $decision === ModerationAction::TRUST
                ? 'The verified remote reply was imported and published by trusted-actor policy.'
                : 'The verified remote reply was imported into moderation.',
        );
    }

    private function storeLocalNoteReply(
        IncomingActivity              $activity,
        RemoteActor                  $remoteActor,
        ValidatedRemoteObject         $object,
        RemoteObject                  $storedObject,
        StoredLocalNoteRepresentation $localNote,
        ModerationAction              $decision,
        int                           $now,
    ): InboxProcessingResult {
        if ($localNote->visibility === 'direct' && $object->visibility !== 'direct') {
            throw new \DomainException('A direct local ActivityPub Note cannot receive a more public reply.');
        }

        $interaction = $this->interactionRepository->create(new NewRemoteInteraction(
            'reply',
            $remoteActor->id,
            $activity->id,
            $storedObject->objectUrl,
            null,
            null,
            '',
            '',
            $this->provenance($activity, $remoteActor, $object),
            $now,
            $localNote->id,
        ));
        $isPublic = $decision === ModerationAction::TRUST
            && $localNote->visibility !== 'direct'
            && \in_array($object->visibility, ['public', 'unlisted'], true);
        if ($isPublic) {
            $this->interactionRepository->setLocalNoteReplyPublic(
                $localNote->id,
                $storedObject->objectUrl,
                true,
                $now,
            );
        }

        if ($decision !== ModerationAction::SILENCE) {
            $this->notificationRepository->create(
                $localNote->actorId,
                $isPublic ? 'reply' : 'moderation_reply',
                'interaction',
                $interaction->id,
                [
                    'activity' => $activity->id,
                    'actor'    => $remoteActor->actorUrl,
                    'object'   => $object->objectUrl,
                    'local_note' => $localNote->publicId,
                ],
                $now,
            );
        }

        return new InboxProcessingResult(
            InboxState::PROCESSED,
            $isPublic
                ? 'The trusted remote reply to a local Note was published in its replies collection.'
                : 'The remote reply to a local Note was stored in the private reader.',
        );
    }

    private function reaction(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        ModerationAction $decision,
        string           $reaction,
        string           $emoji,
        int              $now,
    ): InboxProcessingResult {
        $existing = $this->interactionRepository->findByActivityUrl($activity->id);
        if ($existing instanceof RemoteInteraction) {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The remote interaction was already applied.');
        }

        $targetUrl = $activity->objectIri();
        if ($targetUrl === null) {
            throw new \DomainException('The ActivityPub interaction must identify its target object.');
        }

        $target = $this->reactionTarget($targetUrl);
        if ($target === null) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The remote interaction does not target local content.');
        }

        $type = match ($activity->type) {
            'Like'       => 'like',
            'EmojiReact' => 'emoji_react',
            'Announce'   => 'announce',
            default      => throw new \LogicException('Unexpected ActivityPub reaction type.'),
        };
        $sourceKey = hash('sha256', $activity->id);
        $interaction = $this->interactionRepository->create(new NewRemoteInteraction(
            $type,
            $remoteActor->id,
            $activity->id,
            $targetUrl,
            $target['local_object_id'],
            $target['comment_id'],
            $sourceKey,
            $emoji,
            [
                'activity' => $activity->id,
                'actor'    => $remoteActor->actorUrl,
                'object'   => $targetUrl,
                'inbox'    => $item->id,
            ],
            $now,
            $target['local_note_id'],
        ));
        if ($decision !== ModerationAction::SILENCE) {
            $this->reactionRepository->store(new ReactionAggregate(
                $target['target_type'],
                $target['target_id'],
                'activitypub',
                $sourceKey,
                $reaction,
                $emoji,
                1,
                $now,
                ['activity' => $activity->id, 'actor' => $remoteActor->actorUrl],
            ));
            $this->notificationRepository->create(
                $target['owner_actor_id'],
                $type,
                'interaction',
                $interaction->id,
                ['activity' => $activity->id, 'actor' => $remoteActor->actorUrl, 'object' => $targetUrl],
                $now,
            );
        }

        return new InboxProcessingResult(InboxState::PROCESSED, 'The remote interaction was stored idempotently.');
    }

    private function undo(IncomingActivity $activity, RemoteActor $remoteActor, int $now): InboxProcessingResult
    {
        $objectDocument = $activity->objectDocument();
        if ($objectDocument !== null) {
            $objectActor = $objectDocument['actor'] ?? null;
            if (\is_array($objectActor) && !array_is_list($objectActor)) {
                $objectActor = $objectActor['id'] ?? null;
            }

            if (\is_string($objectActor) && !hash_equals($remoteActor->actorUrl, $objectActor)) {
                throw new \DomainException('An ActivityPub Undo embedded object belongs to another actor.');
            }
        }

        $originalUrl = $activity->objectIri();
        if ($originalUrl === null) {
            throw new \DomainException('An ActivityPub Undo must identify the original activity.');
        }

        $interaction = $this->interactionRepository->findByActivityUrl($originalUrl);
        if (!$interaction instanceof RemoteInteraction) {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The ActivityPub interaction was already absent.');
        }

        if ($interaction->remoteActorId !== $remoteActor->id) {
            throw new \DomainException("An ActivityPub actor cannot Undo another actor's interaction.");
        }

        if ($interaction->state !== 'active') {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The ActivityPub interaction had already ended.');
        }

        if (\in_array($interaction->type, ['like', 'emoji_react', 'announce'], true)) {
            $target = $interaction->remoteObjectUrl === null ? null : $this->reactionTarget($interaction->remoteObjectUrl);
            if ($target !== null) {
                $this->reactionRepository->remove(
                    $target['target_type'],
                    $target['target_id'],
                    'activitypub',
                    $interaction->reactionSourceKey,
                );
            }
        } elseif ($interaction->remoteObjectUrl !== null) {
            $this->objectRepository->delete($interaction->remoteObjectUrl, $remoteActor->id, $now);
            if ($interaction->type === 'reply'
                && $interaction->localCommentId !== null
                && $interaction->localObjectId !== null
            ) {
                $localObject = $this->federationRepository->findObjectById($interaction->localObjectId);
                if ($localObject instanceof StoredObjectRepresentation) {
                    $this->commentImportService->tombstone($interaction->localCommentId, $localObject->contentId);
                }
            }
        }

        $this->interactionRepository->end($originalUrl, $remoteActor->id, 'undone', $now);

        return new InboxProcessingResult(InboxState::PROCESSED, 'The owned remote interaction was undone.');
    }

    private function flag(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $remoteActor,
        ModerationAction $decision,
        int              $now,
    ): InboxProcessingResult {
        $existing = $this->interactionRepository->findByActivityUrl($activity->id);
        if ($existing instanceof RemoteInteraction) {
            return new InboxProcessingResult(InboxState::PROCESSED, 'The remote Flag was already recorded.');
        }

        $urls = $this->objectUrls($activity->document['object'] ?? null);
        $localObject = null;
        foreach ($urls as $url) {
            $localObject = $this->localObjectFromUrl($url);
            if ($localObject instanceof StoredObjectRepresentation) {
                break;
            }
        }

        $targetActorId = $localObject instanceof StoredObjectRepresentation
            ? $localObject->ownerActorId
            : $item->targetLocalActorId;
        if ($targetActorId === null) {
            return new InboxProcessingResult(InboxState::IGNORED, 'The remote Flag does not identify a local moderation target.');
        }

        $interaction = $this->interactionRepository->create(new NewRemoteInteraction(
            'flag',
            $remoteActor->id,
            $activity->id,
            $urls[0] ?? null,
            $localObject instanceof StoredObjectRepresentation ? $localObject->id : null,
            null,
            '',
            '',
            [
                'activity' => $activity->id,
                'actor'    => $remoteActor->actorUrl,
                'objects'  => $urls,
                'content'  => $this->plainText($activity->document['content'] ?? null, 4_000),
            ],
            $now,
        ));
        if ($decision !== ModerationAction::SILENCE) {
            $this->notificationRepository->create(
                $targetActorId,
                'flag',
                'interaction',
                $interaction->id,
                ['activity' => $activity->id, 'actor' => $remoteActor->actorUrl, 'objects' => $urls],
                $now,
            );
        }

        return new InboxProcessingResult(InboxState::PROCESSED, 'The verified remote Flag was added to moderation.');
    }

    private function replyTarget(string $url): ContentReplyTarget|LocalNoteReplyTarget|null
    {
        $localObject = $this->localObjectFromUrl($url);
        if ($localObject instanceof StoredObjectRepresentation && $localObject->state === 'live') {
            return new ContentReplyTarget($localObject, null);
        }

        $localNote = $this->localNoteFromUrl($url);
        if ($localNote instanceof StoredLocalNoteRepresentation && $localNote->state === 'live') {
            return new LocalNoteReplyTarget($localNote);
        }

        $parent = $this->interactionRepository->findReplyByObjectUrl($url);
        if (!$parent instanceof RemoteInteraction) {
            return null;
        }

        if ($parent->localObjectId === null || $parent->localCommentId === null) {
            return null;
        }

        $localObject = $this->federationRepository->findObjectById($parent->localObjectId);
        if (!$localObject instanceof StoredObjectRepresentation) {
            return null;
        }

        return $localObject->state === 'live' ? new ContentReplyTarget($localObject, $parent->localCommentId) : null;
    }

    /**
     * @return array{
     *     owner_actor_id: int,
     *     local_object_id: int|null,
     *     local_note_id: int|null,
     *     comment_id: int|null,
     *     target_type: ReactionAggregateTargetType,
     *     target_id: int
     * }|null
     */
    private function reactionTarget(string $url): ?array
    {
        $object = $this->localObjectFromUrl($url);
        if ($object instanceof StoredObjectRepresentation && $object->state === 'live') {
            return [
                'owner_actor_id' => $object->ownerActorId,
                'local_object_id' => $object->id,
                'local_note_id' => null,
                'comment_id'  => null,
                'target_type' => $object->contentId->type === ContentType::POST
                    ? ReactionAggregateTargetType::POST
                    : ReactionAggregateTargetType::PAGE,
                'target_id'   => $object->contentId->value,
            ];
        }

        $localNote = $this->localNoteFromUrl($url);
        if ($localNote instanceof StoredLocalNoteRepresentation && $localNote->state === 'live') {
            return [
                'owner_actor_id' => $localNote->actorId,
                'local_object_id' => null,
                'local_note_id' => $localNote->id,
                'comment_id' => null,
                'target_type' => ReactionAggregateTargetType::ACTIVITYPUB_NOTE,
                'target_id' => $localNote->id,
            ];
        }

        $reply = $this->interactionRepository->findReplyByObjectUrl($url);
        if (!$reply instanceof RemoteInteraction) {
            return null;
        }

        if ($reply->localCommentId === null || $reply->localObjectId === null) {
            return null;
        }

        $object = $this->federationRepository->findObjectById($reply->localObjectId);
        if (!$object instanceof StoredObjectRepresentation) {
            return null;
        }

        if ($object->state !== 'live') {
            return null;
        }

        return [
            'owner_actor_id' => $object->ownerActorId,
            'local_object_id' => $object->id,
            'local_note_id' => null,
            'comment_id'  => $reply->localCommentId,
            'target_type' => ReactionAggregateTargetType::COMMENT,
            'target_id'   => $reply->localCommentId,
        ];
    }

    /** @return array<int, string> */
    private function localRecipients(
        ClaimedInboxItem     $item,
        ValidatedRemoteObject $object,
        RemoteActor          $remoteActor,
        ContentReplyTarget|LocalNoteReplyTarget|null $replyTarget,
    ): array {
        $recipients = [];
        foreach ($object->recipients as $recipientUrl) {
            $actor = $this->localActorFromUrl($recipientUrl);
            if ($actor instanceof LocalActor && $actor->state === LocalActorState::ACTIVE) {
                $recipients[$actor->id] = 'addressed';
            }
        }

        if ($object->visibility === 'direct') {
            if ($item->targetLocalActorId !== null && !isset($recipients[$item->targetLocalActorId])) {
                throw new \DomainException('The direct ActivityPub object was posted to an actor it does not address.');
            }

            return $recipients;
        }

        if ($replyTarget !== null) {
            $ownerActorId = $replyTarget instanceof ContentReplyTarget
                ? $replyTarget->object->ownerActorId
                : $replyTarget->note->actorId;
            $recipients[$ownerActorId] ??= 'inbox';
        }

        if ($item->targetLocalActorId !== null) {
            $recipients[$item->targetLocalActorId] ??= 'inbox';
        }

        foreach ($this->followRepository->acceptedOutgoingLocalActorIds($remoteActor->id) as $actorId) {
            $recipients[$actorId] ??= 'following';
        }

        return $recipients;
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

    private function localObjectFromUrl(string $url): ?StoredObjectRepresentation
    {
        $prefix = $this->urlGeneratorFactory->create()->resource('/activitypub/objects/');
        if (str_starts_with($url, $prefix)) {
            $publicId = substr($url, \strlen($prefix));
            if (!str_contains($publicId, '/')) {
                return $this->federationRepository->findObject($publicId);
            }
        }

        return $this->federationRepository->findLiveObjectByCanonicalUrl($url);
    }

    private function localNoteFromUrl(string $url): ?StoredLocalNoteRepresentation
    {
        $prefix = $this->urlGeneratorFactory->create()->resource('/activitypub/objects/');
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $publicId = substr($url, \strlen($prefix));
        if (str_contains($publicId, '/')) {
            return null;
        }

        return $this->federationRepository->findLocalNote($publicId);
    }

    /**
     * @return array{activity: string, actor: string, object: string, canonical: string}
     */
    private function provenance(
        IncomingActivity      $activity,
        RemoteActor           $remoteActor,
        ValidatedRemoteObject $object,
    ): array {
        return [
            'activity'  => $activity->id,
            'actor'     => $remoteActor->actorUrl,
            'object'    => $object->objectUrl,
            'canonical' => $object->canonicalUrl,
        ];
    }

    /** @param array<int, string> $recipients */
    private function notifyRecipients(
        array             $recipients,
        string            $type,
        RemoteInteraction $interaction,
        IncomingActivity  $activity,
        RemoteActor       $remoteActor,
        int               $now,
    ): void {
        foreach (array_keys($recipients) as $actorId) {
            $this->notificationRepository->create(
                $actorId,
                $type,
                'interaction',
                $interaction->id,
                ['activity' => $activity->id, 'actor' => $remoteActor->actorUrl],
                $now,
            );
        }
    }

    private function emoji(IncomingActivity $activity): string
    {
        $emoji = $this->plainText($activity->document['content'] ?? null, 64);
        if ($emoji === '') {
            throw new \DomainException('An ActivityPub EmojiReact must contain a bounded emoji or shortcode.');
        }

        return $emoji;
    }

    /** @return list<string> */
    private function objectUrls(mixed $value): array
    {
        $values = \is_array($value) && array_is_list($value) ? $value : [$value];
        if (\count($values) > 32) {
            throw new \InvalidArgumentException('The ActivityPub object reference list is too large.');
        }

        $urls = [];
        foreach ($values as $candidate) {
            $url = $this->objectUrl($candidate);
            if ($url === null) {
                continue;
            }

            $urls[$url] = $url;
        }

        return array_values($urls);
    }

    private function objectUrl(mixed $value): ?string
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return null;
            }

            return $this->objectUrl($value['id'] ?? null);
        }

        if (!\is_string($value)) {
            return null;
        }

        if (!str_starts_with($value, 'https://') || \strlen($value) > 2_048) {
            return null;
        }

        return $value;
    }

    private function plainText(mixed $value, int $maxCharacters): string
    {
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1f\x7f]/u', '', trim(strip_tags($value))) ?? '';

        return mb_substr($value, 0, $maxCharacters);
    }
}
