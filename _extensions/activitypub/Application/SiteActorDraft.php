<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\ActorProfileInputValidator;
use Register\Extension\activitypub\Domain\LocalHandle;

final readonly class SiteActorDraft
{
    /**
     * @param array<string, scalar|null>|null $avatar
     * @param array<string, scalar|null>|null $header
     * @param list<array{name: string, value: string}> $metadata
     */
    public function __construct(
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
        if ($type === ActorType::PERSON) {
            throw new \InvalidArgumentException('The collective site actor cannot use the Person type.');
        }

        ActorProfileInputValidator::validateDisplayName($displayName);
        ActorProfileInputValidator::validateHtml($summaryHtml, 'summary');
        ActorProfileInputValidator::validateProfileUrl($profileUrl);
        ActorProfileInputValidator::validateMedia($avatar, 'avatar');
        ActorProfileInputValidator::validateMedia($header, 'header');
        ActorProfileInputValidator::validateMetadata($metadata);
    }
}
