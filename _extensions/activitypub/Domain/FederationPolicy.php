<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

/** Installation-wide defaults; an explicit per-content choice always wins. */
final readonly class FederationPolicy
{
    public function __construct(
        public bool                $postsEnabled,
        public bool                $pagesEnabled,
        public PostObjectType      $postObjectType,
        public ContentDeliveryMode $contentMode,
        public string              $defaultVisibility,
    ) {
        if (!\in_array($this->defaultVisibility, ['public', 'unlisted'], true)) {
            throw new \InvalidArgumentException('The default ActivityPub visibility is invalid.');
        }
    }

    public static function fromState(FederationState $state): self
    {
        return new self(
            $state->postsEnabled,
            $state->pagesEnabled,
            $state->postObjectType,
            $state->contentMode,
            $state->defaultVisibility,
        );
    }

    public function equals(self $other): bool
    {
        return $this->postsEnabled === $other->postsEnabled
            && $this->pagesEnabled === $other->pagesEnabled
            && $this->postObjectType === $other->postObjectType
            && $this->contentMode === $other->contentMode
            && hash_equals($this->defaultVisibility, $other->defaultVisibility);
    }
}
