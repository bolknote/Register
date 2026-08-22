<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ActorProfileInputValidator;
use Register\Extension\activitypub\Domain\LocalHandle;

/** Public author identity input. It deliberately contains no login name, email, or credentials. */
final readonly class AuthorActorDraft
{
    /**
     * @param array<string, scalar|null>|null $avatar
     * @param array<string, scalar|null>|null $header
     * @param list<array{name: string, value: string}> $metadata
     */
    public function __construct(
        public int         $authorId,
        public LocalHandle $handle,
        public string      $displayName,
        public string      $summaryHtml,
        public string      $profileUrl,
        public ?array      $avatar = null,
        public ?array      $header = null,
        public array       $metadata = [],
        public bool        $discoverable = true,
    ) {
        if ($authorId < 1) {
            throw new \InvalidArgumentException('An ActivityPub author identity requires a positive local author identifier.');
        }

        ActorProfileInputValidator::validateDisplayName($displayName);
        ActorProfileInputValidator::validateHtml($summaryHtml, 'summary');
        ActorProfileInputValidator::validateProfileUrl($profileUrl);
        ActorProfileInputValidator::validateMedia($avatar, 'avatar');
        ActorProfileInputValidator::validateMedia($header, 'header');
        ActorProfileInputValidator::validateMetadata($metadata);
    }
}
