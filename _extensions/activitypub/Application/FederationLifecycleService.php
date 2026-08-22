<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;

/** Safe pause/resume and actor-tombstoning decommission workflow. */
final readonly class FederationLifecycleService
{
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $actorRepository,
        private LocalFederationRepository     $federationRepository,
        private DeliveryRepository            $deliveryRepository,
        private InboxRepository               $inboxRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicIdGenerator             $publicIdGenerator,
        private LocalActivityDocumentBuilder  $activityBuilder,
        private CanonicalJson                  $canonicalJson,
        private DeliveryPlanner               $deliveryPlanner,
        private DeliveryQueue                 $deliveryQueue,
        private InboxQueue                    $inboxQueue,
        private PortableDatabaseTransaction   $transaction,
    ) {
    }

    public function pause(?int $now = null): void
    {
        $timestamp = $now ?? time();
        $state = $this->stateRepository->lifecycleState();
        if ($state === FederationLifecycleState::PAUSED) {
            return;
        }

        if ($state !== FederationLifecycleState::ACTIVE || !$this->stateRepository->pause($timestamp)) {
            throw new \DomainException('Only active ActivityPub federation can be paused.');
        }
    }

    public function resume(?int $now = null): void
    {
        $timestamp = $now ?? time();
        $state = $this->stateRepository->lifecycleState();
        if ($state === FederationLifecycleState::ACTIVE) {
            return;
        }

        if ($state !== FederationLifecycleState::PAUSED || !$this->stateRepository->resume($timestamp)) {
            throw new \DomainException('Only paused ActivityPub federation can be resumed.');
        }

        $this->deliveryQueue->wakeForNextPending();
        $this->inboxQueue->wakeForNextPending();
    }

    /** @return int Number of actor Delete activities created. */
    public function decommission(?int $now = null): int
    {
        $timestamp = $now ?? time();
        $state = $this->stateRepository->lifecycleState();
        if ($state === FederationLifecycleState::DECOMMISSIONED) {
            return 0;
        }

        if ($state === FederationLifecycleState::DECOMMISSIONING) {
            $this->finishIfReady($timestamp);

            return 0;
        }

        if (!\in_array($state, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)) {
            throw new \DomainException('Only published ActivityPub federation can be decommissioned.');
        }

        $created = $this->transaction->run(function () use ($timestamp): int {
            $this->deliveryRepository->recoverStaleClaims($timestamp);
            $this->inboxRepository->recoverStaleClaims($timestamp);
            $this->deliveryRepository->cancelOutstanding(
                'The delivery was cancelled before ActivityPub identity decommission.',
                $timestamp,
            );
            $this->inboxRepository->ignoreOutstanding(
                'The inbox envelope was not processed because ActivityPub identity decommission began.',
                $timestamp,
            );

            $actors = $this->actorRepository->activeActors();
            $urls = $this->urlGeneratorFactory->create();
            $created = 0;
            foreach ($actors as $actor) {
                $deduplicationKey = 'actor-delete:' . $actor->publicId;
                $activity = $this->federationRepository->findActivityByDeduplicationKey($deduplicationKey);
                if (!$activity instanceof \Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation) {
                    $publicId = $this->publicIdGenerator->generate();
                    $document = $this->activityBuilder->deleteActor(
                        $publicId,
                        $actor,
                        $urls,
                        $timestamp,
                    );
                    $serialized = $this->canonicalJson->encode($document);
                    $activity = $this->federationRepository->insertActivity(new NewStoredActivity(
                        $publicId,
                        $actor->id,
                        null,
                        'Delete',
                        'public',
                        ActivityDeliveryIntent::FOLLOWERS,
                        $deduplicationKey,
                        $serialized,
                        hash('sha256', $serialized),
                        $timestamp,
                        $timestamp,
                    ));
                    ++$created;
                }

                $this->deliveryPlanner->plan($activity, $timestamp);
            }

            $tombstoned = $this->actorRepository->tombstoneActiveActors($timestamp);
            if ($tombstoned !== \count($actors)) {
                throw new \RuntimeException('ActivityPub actor state changed during decommission.');
            }

            if (!$this->stateRepository->beginDecommission($timestamp)) {
                throw new \RuntimeException('ActivityPub lifecycle changed during decommission.');
            }

            return $created;
        });

        $this->finishIfReady($timestamp);
        $this->deliveryQueue->wakeForNextPending();

        return $created;
    }

    public function finishIfReady(?int $now = null): bool
    {
        if ($this->stateRepository->lifecycleState() !== FederationLifecycleState::DECOMMISSIONING
            || $this->deliveryRepository->outstandingCount() > 0
        ) {
            return false;
        }

        return $this->stateRepository->finishDecommission($now ?? time());
    }
}
