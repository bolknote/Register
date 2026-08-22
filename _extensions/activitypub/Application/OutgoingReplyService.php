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
use Register\Extension\activitypub\Domain\RemoteObject;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\NewStoredLocalNote;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\RemoteObjectRepository;
use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Presentation\LocalNoteDocumentBuilder;

/** Stores an immutable local Note and Create before scheduling any network delivery. */
final readonly class OutgoingReplyService
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $localActorRepository,
        private RemoteActorRepository         $remoteActorRepository,
        private RemoteObjectRepository        $remoteObjectRepository,
        private ModerationRuleRepository      $moderationRepository,
        private LocalFederationRepository     $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private LocalNoteDocumentBuilder      $noteBuilder,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private PortableDatabaseTransaction   $transaction,
    ) {
    }

    public function reply(
        int    $localActorId,
        int    $remoteObjectId,
        string $plainText,
        string $visibility = 'public',
        ?int   $now = null,
    ): StoredLocalNoteRepresentation {
        $timestamp = $now ?? time();
        [$localActor, $remoteObject, $remoteActor] = $this->target($localActorId, $remoteObjectId, $timestamp);
        if (!\in_array($visibility, ['public', 'unlisted', 'direct'], true)) {
            throw new \InvalidArgumentException('The outgoing ActivityPub reply visibility is invalid.');
        }

        if (\in_array($remoteObject->visibility, ['followers', 'direct'], true) && $visibility !== 'direct') {
            throw new \DomainException('A private remote object cannot receive a broader public reply.');
        }

        $contentHtml = $this->plainTextHtml($plainText);

        return $this->transaction->run(function () use (
            $localActor,
            $remoteObject,
            $remoteActor,
            $visibility,
            $contentHtml,
            $timestamp,
        ): StoredLocalNoteRepresentation {
            $urls       = $this->urlGeneratorFactory->create();
            $noteId     = $this->publicIdGenerator->generate();
            $noteDoc    = $this->noteBuilder->reply(
                $noteId,
                $localActor,
                $remoteActor,
                $urls,
                $remoteObject->objectUrl,
                $contentHtml,
                $visibility,
                $timestamp,
            );
            $noteJson = $this->canonicalJson->encode($noteDoc);
            $note = $this->federationRepository->insertLocalNote(new NewStoredLocalNote(
                $noteId,
                $localActor->id,
                $remoteObject->objectUrl,
                $remoteActor->id,
                $visibility,
                $noteJson,
                hash('sha256', $noteJson),
                $timestamp,
                $timestamp,
                $timestamp,
            ));
            $activityId  = $this->publicIdGenerator->generate();
            $activityDoc = $this->activityBuilder->createAddressed(
                $activityId,
                $localActor,
                $urls,
                $noteDoc,
                $timestamp,
            );
            $activityJson = $this->canonicalJson->encode($activityDoc);
            $intent = $visibility === 'direct'
                ? ActivityDeliveryIntent::DIRECT
                : ActivityDeliveryIntent::FOLLOWERS;
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $activityId,
                $localActor->id,
                null,
                'Create',
                $visibility,
                $intent,
                'reply-create:' . $noteId,
                $activityJson,
                hash('sha256', $activityJson),
                $timestamp,
                $timestamp,
                $note->id,
            ));
            if ($intent === ActivityDeliveryIntent::FOLLOWERS) {
                $this->deliveryPlanner->plan($activity, $timestamp);
            }

            $this->deliveryPlanner->planDirect(
                $activity,
                $remoteActor->inboxUrl,
                $remoteActor->actorUrl,
                $timestamp,
            );

            return $note;
        });
    }

    public function update(
        int    $localActorId,
        int    $localNoteId,
        string $plainText,
        ?int   $now = null,
    ): StoredLocalNoteRepresentation {
        $timestamp = $now ?? time();
        [$localActor, $note, $remoteActor] = $this->localNoteTarget($localActorId, $localNoteId, $timestamp);
        $contentHtml = $this->plainTextHtml($plainText);
        $previousDocument = $this->decode($note->snapshotJson);
        if (($previousDocument['content'] ?? null) === $contentHtml) {
            return $note;
        }

        return $this->transaction->run(function () use (
            $localActor,
            $note,
            $remoteActor,
            $contentHtml,
            $timestamp,
        ): StoredLocalNoteRepresentation {
            $updatedAt = max($timestamp, $note->updatedAt + 1);
            $urls = $this->urlGeneratorFactory->create();
            $noteDocument = $this->noteBuilder->reply(
                $note->publicId,
                $localActor,
                $remoteActor,
                $urls,
                $note->inReplyToUrl,
                $contentHtml,
                $note->visibility,
                $note->publishedAt,
                $updatedAt,
            );
            $noteJson = $this->canonicalJson->encode($noteDocument);
            $updatedNote = $this->federationRepository->updateLocalNote(
                $note,
                $noteJson,
                hash('sha256', $noteJson),
                $updatedAt,
            );
            $activity = $this->storeAddressedActivity(
                'Update',
                $localActor,
                $updatedNote,
                $noteDocument,
                $updatedAt,
            );
            $this->deliver($activity, $remoteActor, $updatedNote->visibility, $updatedAt);

            return $updatedNote;
        });
    }

    public function delete(
        int  $localActorId,
        int  $localNoteId,
        ?int $now = null,
    ): StoredLocalNoteRepresentation {
        $timestamp = $now ?? time();
        [$localActor, $note, $remoteActor] = $this->localNoteTarget(
            $localActorId,
            $localNoteId,
            $timestamp,
            true,
        );
        if ($note->state === 'tombstoned') {
            return $note;
        }

        return $this->transaction->run(function () use (
            $localActor,
            $note,
            $remoteActor,
            $timestamp,
        ): StoredLocalNoteRepresentation {
            $deletedAt = max($timestamp, $note->updatedAt + 1);
            $urls = $this->urlGeneratorFactory->create();
            $previousDocument = $this->decode($note->snapshotJson);
            $activityId = $this->publicIdGenerator->generate();
            $activityDocument = $this->activityBuilder->deleteAddressed(
                $activityId,
                $localActor,
                $urls,
                $note->publicId,
                $previousDocument,
                $deletedAt,
            );
            $activityJson = $this->canonicalJson->encode($activityDocument);
            $intent = $note->visibility === 'direct'
                ? ActivityDeliveryIntent::DIRECT
                : ActivityDeliveryIntent::FOLLOWERS;
            $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                $activityId,
                $localActor->id,
                null,
                'Delete',
                $note->visibility,
                $intent,
                'reply-delete:' . $note->publicId,
                $activityJson,
                hash('sha256', $activityJson),
                $deletedAt,
                $deletedAt,
                $note->id,
            ));
            $deletedNote = $this->federationRepository->tombstoneLocalNote($note, $deletedAt);
            $this->deliver($activity, $remoteActor, $note->visibility, $deletedAt);

            return $deletedNote;
        });
    }

    /** @return array{LocalActor, RemoteObject, RemoteActor} */
    private function target(int $localActorId, int $remoteObjectId, int $now): array
    {
        if ($now < 1 || $this->stateRepository->lifecycleState() !== FederationLifecycleState::ACTIVE) {
            throw new \DomainException('ActivityPub federation must be active for outgoing replies.');
        }

        $localActor  = $this->localActorRepository->findById($localActorId);
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

        if ($remoteActor->state !== 'active') {
            throw new \DomainException('The remote ActivityPub object owner is unavailable.');
        }

        if ($this->moderationRepository->decision($remoteActor) === ModerationAction::BLOCK) {
            throw new \DomainException('The remote ActivityPub object owner is blocked.');
        }

        return [$localActor, $remoteObject, $remoteActor];
    }

    /** @return array{LocalActor, StoredLocalNoteRepresentation, RemoteActor} */
    private function localNoteTarget(
        int  $localActorId,
        int  $localNoteId,
        int  $now,
        bool $allowTombstone = false,
    ): array {
        if ($now < 1 || $this->stateRepository->lifecycleState() !== FederationLifecycleState::ACTIVE) {
            throw new \DomainException('ActivityPub federation must be active for local Note changes.');
        }

        $localActor = $this->localActorRepository->findById($localActorId);
        $note = $this->federationRepository->findLocalNoteById($localNoteId);
        if (!$localActor instanceof LocalActor) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if ($localActor->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('The selected local ActivityPub actor is not active.');
        }

        if (!$note instanceof StoredLocalNoteRepresentation) {
            throw new \DomainException('The selected local ActivityPub Note is unavailable.');
        }

        if ($note->actorId !== $localActor->id
            || (!$allowTombstone && $note->state !== 'live')
        ) {
            throw new \DomainException('The selected local ActivityPub Note is unavailable.');
        }

        $remoteActor = $this->remoteActorRepository->findById($note->remoteActorId);
        if (!$remoteActor instanceof RemoteActor) {
            throw new \DomainException('The local ActivityPub Note recipient is unavailable.');
        }

        if (!\in_array($remoteActor->state, ['active', 'blocked'], true)) {
            throw new \DomainException('The local ActivityPub Note recipient is unavailable.');
        }

        if (!$allowTombstone && $this->moderationRepository->decision($remoteActor) === ModerationAction::BLOCK) {
            throw new \DomainException('The local ActivityPub Note recipient is blocked.');
        }

        return [$localActor, $note, $remoteActor];
    }

    /**
     * @param array<string, mixed> $noteDocument
     */
    private function storeAddressedActivity(
        string                        $type,
        LocalActor                    $localActor,
        StoredLocalNoteRepresentation $note,
        array                         $noteDocument,
        int                           $now,
    ): StoredActivityRepresentation {
        if ($type !== 'Update') {
            throw new \LogicException('Unexpected addressed local Note activity type.');
        }

        $activityId = $this->publicIdGenerator->generate();
        $activityDocument = $this->activityBuilder->updateAddressed(
            $activityId,
            $localActor,
            $this->urlGeneratorFactory->create(),
            $noteDocument,
            $now,
        );
        $activityJson = $this->canonicalJson->encode($activityDocument);
        $intent = $note->visibility === 'direct'
            ? ActivityDeliveryIntent::DIRECT
            : ActivityDeliveryIntent::FOLLOWERS;

        return $this->federationRepository->insertActivity(new NewStoredActivity(
            $activityId,
            $localActor->id,
            null,
            $type,
            $note->visibility,
            $intent,
            'reply-update:' . $note->publicId . ':' . substr($note->snapshotHash, 0, 16),
            $activityJson,
            hash('sha256', $activityJson),
            $now,
            $now,
            $note->id,
        ));
    }

    private function deliver(
        StoredActivityRepresentation $activity,
        RemoteActor                  $remoteActor,
        string                       $visibility,
        int                          $now,
    ): void {
        if ($visibility !== 'direct') {
            $this->deliveryPlanner->plan($activity, $now);
        }

        $this->deliveryPlanner->planDirect(
            $activity,
            $remoteActor->inboxUrl,
            $remoteActor->actorUrl,
            $now,
        );
    }

    private function plainTextHtml(string $plainText): string
    {
        $plainText = trim(str_replace(["\r\n", "\r"], "\n", $plainText));
        if ($plainText === ''
            || !mb_check_encoding($plainText, 'UTF-8')
            || mb_strlen($plainText) > 10_000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $plainText) === 1
        ) {
            throw new \InvalidArgumentException('An outgoing ActivityPub reply must contain at most 10,000 valid characters.');
        }

        $paragraphs = preg_split('/\n{2,}/', $plainText);
        if ($paragraphs === false) {
            throw new \RuntimeException('The outgoing ActivityPub reply could not be split into paragraphs.');
        }

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $escaped = htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            if ($escaped !== '') {
                $html .= '<p>' . nl2br($escaped, false) . '</p>';
            }
        }

        if ($html === '') {
            throw new \InvalidArgumentException('An outgoing ActivityPub reply cannot be empty.');
        }

        return $html;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored local ActivityPub Note is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('A stored local ActivityPub Note must be a JSON object.');
        }

        return $document;
    }
}
