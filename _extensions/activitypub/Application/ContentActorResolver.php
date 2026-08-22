<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\FederationUrlGenerator;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;

/** Centralizes the actor and audience rules shared by live projection and editorial preview. */
final readonly class ContentActorResolver
{
    public function __construct(private LocalActorRepository $actorRepository)
    {
    }

    public function ownerFor(?int $authorId): LocalActor
    {
        $authorActor = $authorId === null ? null : $this->actorRepository->activeAuthorActor($authorId);
        $actor = $authorActor ?? $this->actorRepository->siteActor();
        if (!$actor instanceof LocalActor) {
            throw new \RuntimeException('Active federation has no active actor for published content.');
        }

        if ($actor->state !== LocalActorState::ACTIVE) {
            throw new \RuntimeException('Active federation has no active actor for published content.');
        }

        return $actor;
    }

    public function collectiveFor(LocalActor $owner): ?LocalActor
    {
        if ($owner->kind !== ActorKind::AUTHOR) {
            return null;
        }

        $collective = $this->actorRepository->siteActor();
        if (!$collective instanceof LocalActor) {
            throw new \RuntimeException('An ActivityPub author publication has no active collective site actor.');
        }

        if ($collective->state !== LocalActorState::ACTIVE) {
            throw new \RuntimeException('An ActivityPub author publication has no active collective site actor.');
        }

        return $collective;
    }

    /** @return list<string> */
    public function additionalFollowerCollections(
        LocalActor             $owner,
        FederationUrlGenerator $urls,
    ): array {
        $collective = $this->collectiveFor($owner);

        return $collective instanceof LocalActor
            ? [$urls->actorFollowers($collective->publicId)]
            : [];
    }

    /** @return non-empty-list<int> */
    public function followerActorIds(LocalActor $owner): array
    {
        $collective = $this->collectiveFor($owner);

        return $collective instanceof LocalActor
            ? [$owner->id, $collective->id]
            : [$owner->id];
    }
}
