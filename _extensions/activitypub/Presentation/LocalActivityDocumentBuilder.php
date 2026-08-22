<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Presentation;

use s2_extensions\activitypub\Domain\FederationUrlGenerator;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Infrastructure\StoredObjectRepresentation;

final class LocalActivityDocumentBuilder
{
    /**
     * @param array<string, mixed> $object
     * @param list<string> $additionalFollowerCollections
     * @param list<string> $directRecipients
     * @return array<string, mixed>
     */
    public function change(
        string                 $type,
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $object,
        string                 $visibility,
        int                    $publishedAt,
        array                  $additionalFollowerCollections = [],
        array                  $directRecipients = [],
    ): array {
        if (!\in_array($type, ['Create', 'Update'], true)) {
            throw new \InvalidArgumentException('A local content change must be Create or Update.');
        }

        unset($object['@context']);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => $type,
            'actor'     => $urls->actor($actor->publicId),
            'published' => $this->date($publishedAt),
            ...$this->addressing($urls, $actor, $visibility, $additionalFollowerCollections, $directRecipients),
            'object'    => $object,
        ];
    }

    /**
     * @param list<string> $additionalFollowerCollections
     * @param list<string> $directRecipients
     * @return array<string, mixed>
     */
    public function delete(
        string                     $activityPublicId,
        LocalActor                 $actor,
        FederationUrlGenerator     $urls,
        StoredObjectRepresentation $object,
        int                        $deletedAt,
        array                      $additionalFollowerCollections = [],
        array                      $directRecipients = [],
    ): array {
        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Delete',
            'actor'     => $urls->actor($actor->publicId),
            'published' => $this->date($deletedAt),
            ...$this->addressing(
                $urls,
                $actor,
                $object->visibility,
                $additionalFollowerCollections,
                $directRecipients,
            ),
            'object'    => [
                'id'         => $urls->object($object->publicId),
                'type'       => 'Tombstone',
                'formerType' => $object->objectType,
                'deleted'    => $this->date($deletedAt),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function deleteActor(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        int                    $deletedAt,
    ): array {
        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Delete',
            'actor'     => $urls->actor($actor->publicId),
            'published' => $this->date($deletedAt),
            'to'        => [ActivityStreamsContext::PUBLIC_COLLECTION],
            'cc'        => [$urls->actorFollowers($actor->publicId)],
            'object'    => [
                'id'         => $urls->actor($actor->publicId),
                'type'       => 'Tombstone',
                'formerType' => $actor->type->value,
                'deleted'    => $this->date($deletedAt),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $actorDocument
     * @return array<string, mixed>
     */
    public function updateActor(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $actorDocument,
        int                    $publishedAt,
    ): array {
        $actorUrl = $urls->actor($actor->publicId);
        if (($actorDocument['id'] ?? null) !== $actorUrl) {
            throw new \InvalidArgumentException('A local actor Update must embed its own actor document.');
        }

        unset($actorDocument['@context']);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Update',
            'actor'     => $actorUrl,
            'object'    => $actorDocument,
            'published' => $this->date($publishedAt),
            'to'        => [ActivityStreamsContext::PUBLIC_COLLECTION],
            'cc'        => [$urls->actorFollowers($actor->publicId)],
        ];
    }

    /** @return array<string, mixed> */
    public function moveActor(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        string                 $targetActorUrl,
        int                    $publishedAt,
    ): array {
        $this->remoteUrl($targetActorUrl);
        $actorUrl = $urls->actor($actor->publicId);
        if (hash_equals($actorUrl, $targetActorUrl)) {
            throw new \InvalidArgumentException('A local ActivityPub actor cannot move to itself.');
        }

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Move',
            'actor'     => $actorUrl,
            'object'    => $actorUrl,
            'target'    => $targetActorUrl,
            'published' => $this->date($publishedAt),
            'to'        => [$urls->actorFollowers($actor->publicId)],
        ];
    }

    /**
     * @param array<string, mixed> $follow
     * @return array<string, mixed>
     */
    public function accept(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $follow,
        string                 $recipient,
        int                    $publishedAt,
    ): array {
        unset($follow['@context']);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Accept',
            'actor'     => $urls->actor($actor->publicId),
            'object'    => $follow,
            'to'        => [$recipient],
            'published' => $this->date($publishedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function follow(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        string                 $remoteActorUrl,
        int                    $publishedAt,
    ): array {
        $this->remoteUrl($remoteActorUrl);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Follow',
            'actor'     => $urls->actor($actor->publicId),
            'object'    => $remoteActorUrl,
            'to'        => [$remoteActorUrl],
            'published' => $this->date($publishedAt),
        ];
    }

    /**
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    public function undo(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $original,
        string                 $recipient,
        int                    $publishedAt,
    ): array {
        $this->remoteUrl($recipient);
        unset($original['@context']);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Undo',
            'actor'     => $urls->actor($actor->publicId),
            'object'    => $original,
            'to'        => [$recipient],
            'published' => $this->date($publishedAt),
        ];
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public function createAddressed(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $object,
        int                    $publishedAt,
    ): array {
        return $this->addressedChange('Create', $activityPublicId, $actor, $urls, $object, $publishedAt);
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public function updateAddressed(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $object,
        int                    $publishedAt,
    ): array {
        return $this->addressedChange('Update', $activityPublicId, $actor, $urls, $object, $publishedAt);
    }

    /**
     * @param array<string, mixed> $previousObject
     * @return array<string, mixed>
     */
    public function deleteAddressed(
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        string                 $objectPublicId,
        array                  $previousObject,
        int                    $deletedAt,
    ): array {
        [$to, $cc] = $this->addressedRecipients($previousObject);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => 'Delete',
            'actor'     => $urls->actor($actor->publicId),
            'published' => $this->date($deletedAt),
            'to'        => $to,
            'cc'        => $cc,
            'object'    => [
                'id'         => $urls->object($objectPublicId),
                'type'       => 'Tombstone',
                'formerType' => 'Note',
                'deleted'    => $this->date($deletedAt),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function addressedChange(
        string                 $type,
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        array                  $object,
        int                    $publishedAt,
    ): array {
        if (!\in_array($type, ['Create', 'Update'], true)) {
            throw new \InvalidArgumentException('An addressed local ActivityPub change type is invalid.');
        }

        $objectId = $object['id'] ?? null;
        if (!\is_string($objectId) || !str_starts_with($objectId, 'https://')) {
            throw new \InvalidArgumentException('An addressed local ActivityPub object is invalid.');
        }

        [$to, $cc] = $this->addressedRecipients($object);
        unset($object['@context']);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => $type,
            'actor'     => $urls->actor($actor->publicId),
            'published' => $this->date($publishedAt),
            'to'        => $to,
            'cc'        => $cc,
            'object'    => $object,
        ];
    }

    /**
     * @param array<string, mixed> $object
     * @return array{list<string>, list<string>}
     */
    private function addressedRecipients(array $object): array
    {
        $to = $object['to'] ?? null;
        $cc = $object['cc'] ?? null;
        if (!\is_array($to) || !\is_array($cc) || !array_is_list($to) || !array_is_list($cc)) {
            throw new \InvalidArgumentException('An addressed local ActivityPub object is invalid.');
        }

        foreach ([...$to, ...$cc] as $recipient) {
            if (!\is_string($recipient) || !str_starts_with($recipient, 'https://')) {
                throw new \InvalidArgumentException('An addressed local ActivityPub recipient is invalid.');
            }
        }

        return [$to, $cc];
    }

    /**
     * @param list<string> $additionalFollowerCollections
     * @return array<string, mixed>
     */
    public function featuredChange(
        string                 $type,
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        string                 $objectUrl,
        string                 $visibility,
        int                    $publishedAt,
        array                  $additionalFollowerCollections = [],
    ): array {
        if (!\in_array($type, ['Add', 'Remove'], true)) {
            throw new \InvalidArgumentException('A local featured collection change must be Add or Remove.');
        }

        $this->remoteUrl($objectUrl);

        return [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => $type,
            'actor'     => $urls->actor($actor->publicId),
            'object'    => $objectUrl,
            'target'    => $urls->actorFeatured($actor->publicId),
            'published' => $this->date($publishedAt),
            ...$this->addressing($urls, $actor, $visibility, $additionalFollowerCollections),
        ];
    }

    /** @return array<string, mixed> */
    public function interaction(
        string                 $type,
        string                 $activityPublicId,
        LocalActor             $actor,
        FederationUrlGenerator $urls,
        string                 $remoteActorUrl,
        string                 $targetUrl,
        string                 $emoji,
        int                    $publishedAt,
    ): array {
        if (!\in_array($type, ['Like', 'EmojiReact', 'Announce'], true)) {
            throw new \InvalidArgumentException('A local ActivityPub interaction type is invalid.');
        }

        $this->remoteUrl($remoteActorUrl);
        $this->remoteUrl($targetUrl);
        if (($type === 'EmojiReact' && ($emoji === '' || mb_strlen($emoji) > 64))
            || ($type !== 'EmojiReact' && $emoji !== '')
        ) {
            throw new \InvalidArgumentException('A local ActivityPub interaction emoji is invalid.');
        }

        $document = [
            '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'        => $urls->activity($activityPublicId),
            'type'      => $type,
            'actor'     => $urls->actor($actor->publicId),
            'object'    => $targetUrl,
            'published' => $this->date($publishedAt),
        ];
        if ($type === 'Announce') {
            $document['to'] = [ActivityStreamsContext::PUBLIC_COLLECTION];
            $document['cc'] = [$remoteActorUrl, $urls->actorFollowers($actor->publicId)];
        } else {
            $document['to'] = [$remoteActorUrl];
        }

        if ($type === 'EmojiReact') {
            $document['content'] = $emoji;
        }

        return $document;
    }

    /**
     * @param list<string> $additionalFollowerCollections
     * @param list<string> $directRecipients
     * @return array{to: list<string>, cc: list<string>}
     */
    private function addressing(
        FederationUrlGenerator $urls,
        LocalActor             $actor,
        string                 $visibility,
        array                  $additionalFollowerCollections = [],
        array                  $directRecipients = [],
    ): array
    {
        $ownerFollowers = $urls->actorFollowers($actor->publicId);
        $followers      = [$ownerFollowers => $ownerFollowers];
        foreach ($additionalFollowerCollections as $collection) {
            $this->remoteUrl($collection);
            $followers[$collection] = $collection;
        }

        $followers = array_values($followers);
        $direct = [];
        foreach ($directRecipients as $recipient) {
            $this->remoteUrl($recipient);
            $direct[$recipient] = $recipient;
        }

        $direct = array_values($direct);
        $publicRecipients = [ActivityStreamsContext::PUBLIC_COLLECTION => ActivityStreamsContext::PUBLIC_COLLECTION];
        $unlistedRecipients = [];
        foreach ($followers as $followerCollection) {
            $unlistedRecipients[$followerCollection] = $followerCollection;
        }

        foreach ($direct as $recipient) {
            $publicRecipients[$recipient] = $recipient;
            $unlistedRecipients[$recipient] = $recipient;
        }

        return match ($visibility) {
            'public' => [
                'to' => array_values($publicRecipients),
                'cc' => $followers,
            ],
            'unlisted' => [
                'to' => array_values($unlistedRecipients),
                'cc' => [ActivityStreamsContext::PUBLIC_COLLECTION],
            ],
            default => throw new \InvalidArgumentException('The local ActivityPub visibility is invalid.'),
        };
    }

    private function date(int $timestamp): string
    {
        if ($timestamp < 1) {
            throw new \InvalidArgumentException('An ActivityPub activity timestamp must be positive.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function remoteUrl(string $url): void
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('A directly addressed ActivityPub recipient must be bounded HTTPS.');
        }
    }
}
