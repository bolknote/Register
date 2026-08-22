<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class NewLocalActor
{
    /**
     * @param array<string, scalar|null>|null $avatar
     * @param array<string, scalar|null>|null $header
     * @param list<array{name: string, value: string}> $metadata
     */
    public function __construct(
        public string      $publicId,
        public ActorKind   $kind,
        public ?int        $authorId,
        public ActorType   $type,
        public LocalHandle $handle,
        public string      $displayName,
        public string      $summaryHtml,
        public string      $profileUrl,
        public ?array      $avatar = null,
        public ?array      $header = null,
        public array       $metadata = [],
        public bool        $discoverable = true,
    ) {
        if (!$type->isAllowedFor($kind)) {
            throw new \InvalidArgumentException('The ActivityPub actor type is incompatible with its local kind.');
        }

        if (($kind === ActorKind::SITE) !== ($authorId === null)) {
            throw new \InvalidArgumentException('Only an ActivityPub author actor may have a local author binding.');
        }

        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            throw new \InvalidArgumentException('The ActivityPub actor identifier is invalid.');
        }

        ActorProfileInputValidator::validateDisplayName($displayName);
        ActorProfileInputValidator::validateHtml($summaryHtml, 'summary');
        ActorProfileInputValidator::validateProfileUrl($profileUrl);
        ActorProfileInputValidator::validateMedia($avatar, 'avatar');
        ActorProfileInputValidator::validateMedia($header, 'header');
        ActorProfileInputValidator::validateMetadata($metadata);
    }
}
