<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;

final readonly class PublicFederationAccess
{
    public function __construct(private FederationStateRepository $stateRepository)
    {
    }

    public function installationIsPublic(): bool
    {
        return $this->stateRepository->state()->hasPublicIdentity();
    }

    public function actorIsPublic(LocalActor $actor): bool
    {
        return $this->installationIsPublic()
            && \in_array($actor->state, [
                LocalActorState::ACTIVE,
                LocalActorState::MOVED,
                LocalActorState::TOMBSTONED,
            ], true);
    }
}
