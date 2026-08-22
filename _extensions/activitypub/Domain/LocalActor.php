<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class LocalActor
{
    /**
     * @param array<string, scalar|null>|null $avatar
     * @param array<string, scalar|null>|null $header
     * @param list<array{name: string, value: string}> $metadata
     */
    public function __construct(
        public int             $id,
        public string          $publicId,
        public ActorKind       $kind,
        public ?int            $authorId,
        public ActorType       $type,
        public string          $handle,
        public string          $displayName,
        public string          $summaryHtml,
        public string          $profileUrl,
        public ?array          $avatar,
        public ?array          $header,
        public array           $metadata,
        public LocalActorState $state,
        public ?string         $movedToUrl,
        public ?int            $movedAt,
        public bool            $discoverable,
        public int             $createdAt,
        public ?int            $activatedAt,
        public ?int            $deactivatedAt,
        public int             $updatedAt,
    ) {
        if (($state === LocalActorState::MOVED) !== ($movedToUrl !== null)
            || (($movedToUrl === null) !== ($movedAt === null))
            || ($movedToUrl !== null && (!str_starts_with($movedToUrl, 'https://') || \strlen($movedToUrl) > 2_048))
            || ($movedAt !== null && $movedAt < 1)
        ) {
            throw new \InvalidArgumentException('A local ActivityPub actor migration state is invalid.');
        }
    }
}
