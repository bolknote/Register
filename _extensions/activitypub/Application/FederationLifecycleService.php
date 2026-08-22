<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Delivery\DeliveryPlanner;
use s2_extensions\activitypub\Delivery\DeliveryQueue;
use s2_extensions\activitypub\Domain\ActivityDeliveryIntent;
use s2_extensions\activitypub\Domain\FederationLifecycleState;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Inbox\InboxQueue;
use s2_extensions\activitypub\Infrastructure\DeliveryRepository;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\InboxRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\NewStoredActivity;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Presentation\LocalActivityDocumentBuilder;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;

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
                if (!$activity instanceof \s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation) {
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
