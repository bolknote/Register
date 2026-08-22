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
use s2_extensions\activitypub\Domain\RemoteActor;

final readonly class LocalNoteDocumentBuilder
{
    /** @return array<string, mixed> */
    public function reply(
        string                 $publicId,
        LocalActor             $actor,
        RemoteActor            $remoteActor,
        FederationUrlGenerator $urls,
        string                 $inReplyToUrl,
        string                 $contentHtml,
        string                 $visibility,
        int                    $publishedAt,
        ?int                   $updatedAt = null,
    ): array {
        if (!str_starts_with($inReplyToUrl, 'https://')
            || \strlen($inReplyToUrl) > 2_048
            || $contentHtml === ''
            || \strlen($contentHtml) > 65_535
            || ($updatedAt !== null && $updatedAt < $publishedAt)
        ) {
            throw new \InvalidArgumentException('A local ActivityPub reply object is invalid.');
        }

        $document = [
            '@context'     => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'           => $urls->object($publicId),
            'type'         => 'Note',
            'attributedTo' => $urls->actor($actor->publicId),
            'url'          => $urls->object($publicId),
            'content'      => $contentHtml,
            'mediaType'    => 'text/html',
            'inReplyTo'    => $inReplyToUrl,
            'published'    => $this->date($publishedAt),
            'updated'      => $this->date($updatedAt ?? $publishedAt),
            ...$this->addressing($actor, $remoteActor, $urls, $visibility),
        ];
        if ($visibility !== 'direct') {
            $document['replies'] = $urls->objectReplies($publicId);
        }

        return $document;
    }

    /** @return array{to: list<string>, cc: list<string>} */
    private function addressing(
        LocalActor             $actor,
        RemoteActor            $remoteActor,
        FederationUrlGenerator $urls,
        string                 $visibility,
    ): array {
        $followers = $urls->actorFollowers($actor->publicId);

        return match ($visibility) {
            'public' => [
                'to' => [ActivityStreamsContext::PUBLIC_COLLECTION],
                'cc' => [$remoteActor->actorUrl, $followers],
            ],
            'unlisted' => [
                'to' => [$remoteActor->actorUrl, $followers],
                'cc' => [ActivityStreamsContext::PUBLIC_COLLECTION],
            ],
            'direct' => [
                'to' => [$remoteActor->actorUrl],
                'cc' => [],
            ],
            default => throw new \InvalidArgumentException('The local ActivityPub reply visibility is invalid.'),
        };
    }

    private function date(int $timestamp): string
    {
        if ($timestamp < 1) {
            throw new \InvalidArgumentException('A local ActivityPub Note timestamp must be positive.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
