<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;

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
