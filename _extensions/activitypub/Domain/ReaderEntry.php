<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class ReaderEntry
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public int     $objectId,
        public string  $objectUrl,
        public string  $objectType,
        public ?string $inReplyToUrl,
        public string  $visibility,
        public int     $sortAt,
        public int     $remoteActorId,
        public string  $actorUrl,
        public string  $preferredUsername,
        public string  $displayName,
        public string  $recipientKind,
        public array   $document,
    ) {
        if ($objectId < 1
            || !str_starts_with($objectUrl, 'https://')
            || !\in_array($objectType, ['Note', 'Article', 'Page'], true)
            || ($inReplyToUrl !== null && !str_starts_with($inReplyToUrl, 'https://'))
            || !\in_array($visibility, ['public', 'unlisted', 'followers', 'direct'], true)
            || $sortAt < 0
            || $remoteActorId < 1
            || !str_starts_with($actorUrl, 'https://')
            || $preferredUsername === ''
            || !\in_array($recipientKind, ['addressed', 'inbox', 'following'], true)
        ) {
            throw new \InvalidArgumentException('An ActivityPub reader entry is invalid.');
        }
    }
}
