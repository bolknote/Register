<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class ValidatedRemoteObject
{
    /**
     * @param list<string> $recipients
     * @param array<string, mixed> $sanitizedDocument
     */
    public function __construct(
        public string  $objectUrl,
        public string  $objectType,
        public string  $actorUrl,
        public string  $canonicalUrl,
        public ?string $inReplyToUrl,
        public string  $contentHtml,
        public string  $displayName,
        public string  $summary,
        public bool    $sensitive,
        public string  $visibility,
        public array          $recipients,
        public array   $sanitizedDocument,
        public int     $publishedAt,
        public int     $updatedAt,
    ) {
        if (!str_starts_with($objectUrl, 'https://')
            || !\in_array($objectType, ['Note', 'Article', 'Page'], true)
            || !str_starts_with($actorUrl, 'https://')
            || !str_starts_with($canonicalUrl, 'https://')
            || ($inReplyToUrl !== null && !str_starts_with($inReplyToUrl, 'https://'))
            || $contentHtml === ''
            || !\in_array($visibility, ['public', 'unlisted', 'followers', 'direct'], true)
            || $publishedAt < 0
            || $updatedAt < 0
        ) {
            throw new \InvalidArgumentException('A validated remote ActivityPub object is invalid.');
        }
    }
}
