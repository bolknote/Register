<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Presentation;

use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorKey;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;

final readonly class ActorDocumentBuilder
{
    public function __construct(
        private FederationStateRepository   $stateRepository,
        private LocalActorRepository         $actorRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(LocalActor $actor): array
    {
        $state = $this->stateRepository->state();
        $urls  = $this->urlGeneratorFactory->create();
        $id    = $urls->actor($actor->publicId);
        if ($actor->state === LocalActorState::TOMBSTONED
            || $state->lifecycle === FederationLifecycleState::DECOMMISSIONED
        ) {
            return [
                '@context' => ActivityStreamsContext::ACTIVITY_STREAMS,
                'id'       => $id,
                'type'     => 'Tombstone',
                'formerType' => $actor->type->value,
                'deleted'  => $this->date($actor->deactivatedAt ?? $state->decommissionedAt ?? $actor->updatedAt),
            ];
        }

        if (!\in_array($actor->state, [LocalActorState::ACTIVE, LocalActorState::MOVED], true)) {
            throw new \DomainException('An unpublished ActivityPub actor has no public document.');
        }

        $key = $this->actorRepository->currentKey($actor->id);
        if (!$key instanceof LocalActorKey) {
            throw new \RuntimeException('The public ActivityPub actor has no usable signing key.');
        }

        if ($key->destroyedAt !== null) {
            throw new \RuntimeException('The public ActivityPub actor has no usable signing key.');
        }

        $document = [
            '@context'                  => ActivityStreamsContext::actor(),
            'id'                        => $id,
            'type'                      => $actor->type->value,
            'preferredUsername'         => $actor->handle,
            'name'                      => $actor->displayName,
            'summary'                   => $actor->summaryHtml,
            'url'                       => $actor->profileUrl,
            'inbox'                     => $urls->actorInbox($actor->publicId),
            'outbox'                    => $urls->actorOutbox($actor->publicId),
            'followers'                 => $urls->actorFollowers($actor->publicId),
            'following'                 => $urls->actorFollowing($actor->publicId),
            'featured'                  => $urls->actorFeatured($actor->publicId),
            'endpoints'                 => ['sharedInbox' => $urls->sharedInbox()],
            'manuallyApprovesFollowers' => !$state->autoAcceptFollows,
            'discoverable'              => $actor->discoverable,
            'published'                 => $this->date($actor->activatedAt ?? $actor->createdAt),
            'publicKey'                 => [
                'id'           => $urls->key($key->publicId),
                'owner'        => $id,
                'publicKeyPem' => $key->publicKeyPem,
            ],
        ];
        if ($actor->state === LocalActorState::MOVED) {
            if ($actor->movedToUrl === null) {
                throw new \RuntimeException('A moved ActivityPub actor has no migration target.');
            }

            $document['movedTo'] = $actor->movedToUrl;
        }

        $icon = $this->mediaDocument($actor->avatar);
        if ($icon !== null) {
            $document['icon'] = $icon;
        }

        $image = $this->mediaDocument($actor->header);
        if ($image !== null) {
            $document['image'] = $image;
        }

        if ($actor->metadata !== []) {
            $document['attachment'] = array_map(
                static fn(array $entry): array => [
                    'type'  => 'PropertyValue',
                    'name'  => $entry['name'],
                    'value' => $entry['value'],
                ],
                $actor->metadata,
            );
        }

        $aliases = array_values(array_filter(
            $this->actorRepository->handlesForActor($actor->id),
            static fn(string $handle): bool => $handle !== $actor->handle,
        ));
        if ($aliases !== []) {
            $origin = $state->canonicalOrigin;
            if (!$origin instanceof \Register\Extension\activitypub\Domain\CanonicalOrigin) {
                throw new \RuntimeException('A public ActivityPub actor has no canonical origin.');
            }

            $document['alsoKnownAs'] = array_map(
                static fn(string $handle): string => 'acct:' . $handle . '@' . $origin->authority(),
                $aliases,
            );
        }

        return $document;
    }

    /**
     * @param array<string, scalar|null>|null $media
     * @return array<string, string|int>|null
     */
    private function mediaDocument(?array $media): ?array
    {
        if ($media === null) {
            return null;
        }

        $url = $media['url'] ?? null;
        if (!\is_string($url)) {
            return null;
        }

        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $document = ['type' => 'Image', 'url' => $url];
        foreach (['mediaType', 'name', 'blurhash'] as $name) {
            $value = $media[$name] ?? null;
            if (\is_string($value) && $value !== '') {
                $document[$name] = $value;
            }
        }

        foreach (['width', 'height'] as $name) {
            $value = $media[$name] ?? null;
            if (\is_int($value) && $value > 0) {
                $document[$name] = $value;
            }
        }

        return $document;
    }

    private function date(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
