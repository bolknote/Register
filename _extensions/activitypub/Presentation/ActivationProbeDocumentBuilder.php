<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Presentation;

use s2_extensions\activitypub\Application\ActivationReadinessAttempt;
use s2_extensions\activitypub\Domain\FederationUrlGenerator;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;

/** Produces the future canonical identity only through an unguessable, expiring probe query. */
final readonly class ActivationProbeDocumentBuilder
{
    public function __construct(
        private LocalActorRepository      $actorRepository,
        private FederationStateRepository $stateRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function actor(ActivationReadinessAttempt $attempt, LocalActor $actor): array
    {
        if ($actor->id !== $attempt->actorId || $actor->state !== LocalActorState::DRAFT) {
            throw new \DomainException('The ActivityPub activation probe does not identify an unpublished actor.');
        }

        $key = $this->actorRepository->currentKey($actor->id);
        if (!$key instanceof LocalActorKey) {
            throw new \RuntimeException('The ActivityPub activation probe actor has no usable key.');
        }

        if ($key->destroyedAt !== null) {
            throw new \RuntimeException('The ActivityPub activation probe actor has no usable key.');
        }

        $urls = new FederationUrlGenerator($attempt->canonicalOrigin, $attempt->basePath);
        $id   = $urls->actor($actor->publicId);
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
            'manuallyApprovesFollowers' => !$this->stateRepository->state()->autoAcceptFollows,
            'discoverable'              => $actor->discoverable,
            'published'                 => gmdate('Y-m-d\TH:i:s\Z', $actor->createdAt),
            'publicKey'                 => [
                'id'           => $urls->key($key->publicId),
                'owner'        => $id,
                'publicKeyPem' => $key->publicKeyPem,
            ],
        ];
        $icon = $this->mediaDocument($actor->avatar);
        if ($icon !== null) {
            $document['icon'] = $icon;
        }

        $image = $this->mediaDocument($actor->header);
        if ($image !== null) {
            $document['image'] = $image;
        }

        if ($actor->metadata !== []) {
            $document['attachment'] = array_map(static fn(array $entry): array => [
                'type'  => 'PropertyValue',
                'name'  => $entry['name'],
                'value' => $entry['value'],
            ], $actor->metadata);
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public function webFinger(ActivationReadinessAttempt $attempt, LocalActor $actor): array
    {
        $urls       = new FederationUrlGenerator($attempt->canonicalOrigin, $attempt->basePath);
        $actorUrl   = $urls->actor($actor->publicId);
        $account    = 'acct:' . $actor->handle . '@' . $attempt->canonicalOrigin->authority();
        $probeUrl   = $actorUrl . '?activation_probe=' . rawurlencode($attempt->id);

        return [
            'subject' => $account,
            'aliases' => [$actorUrl, $actor->profileUrl, $account],
            'links'   => [
                [
                    'rel'  => 'self',
                    'type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
                    'href' => $probeUrl,
                ],
                [
                    'rel'  => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $actor->profileUrl,
                ],
            ],
        ];
    }

    /**
     * @param array<string, scalar|null>|null $media
     * @return array<string, string|int>|null
     */
    private function mediaDocument(?array $media): ?array
    {
        $url = $media['url'] ?? null;
        if (!\is_string($url)) {
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
}
