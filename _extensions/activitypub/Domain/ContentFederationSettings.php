<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

use Register\Content\ContentId;
use Register\Content\ContentType;

/** Validated per-content overrides resolved against the installation federation profile. */
final readonly class ContentFederationSettings
{
    public function __construct(
        public ContentId              $contentId,
        public ContentPublicationMode $publicationMode = ContentPublicationMode::INHERIT,
        public ?ContentDeliveryMode   $deliveryMode = null,
        public ?PostObjectType        $postObjectType = null,
        public ?string                $visibility = null,
        public string                 $summary = '',
        public ?string                $language = null,
    ) {
        if ($this->contentId->type === ContentType::PAGE && $this->postObjectType instanceof \s2_extensions\activitypub\Domain\PostObjectType) {
            throw new \InvalidArgumentException('A page cannot override its frozen ActivityPub Page type.');
        }

        if ($this->visibility !== null && !\in_array($this->visibility, ['public', 'unlisted'], true)) {
            throw new \InvalidArgumentException('The per-content ActivityPub visibility is invalid.');
        }

        if (!mb_check_encoding($this->summary, 'UTF-8') || mb_strlen($this->summary) > 500) {
            throw new \InvalidArgumentException('The per-content ActivityPub summary is invalid.');
        }

        if ($this->language !== null
            && preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/Di', $this->language) !== 1
        ) {
            throw new \InvalidArgumentException('The per-content ActivityPub language is invalid.');
        }
    }

    public static function inherited(ContentId $contentId): self
    {
        return new self($contentId);
    }

    public function isEnabled(FederationState $state): bool
    {
        return match ($this->publicationMode) {
            ContentPublicationMode::ENABLED  => true,
            ContentPublicationMode::DISABLED => false,
            ContentPublicationMode::INHERIT  => $this->contentId->type === ContentType::POST
                ? $state->postsEnabled
                : $state->pagesEnabled,
        };
    }

    public function resolvesObjectType(FederationState $state): string
    {
        if ($this->contentId->type === ContentType::PAGE) {
            return 'Page';
        }

        return ($this->postObjectType ?? $state->postObjectType)->value;
    }

    public function resolvesDeliveryMode(FederationState $state): ContentDeliveryMode
    {
        return $this->deliveryMode ?? $state->contentMode;
    }

    public function resolvesVisibility(FederationState $state): string
    {
        return $this->visibility ?? $state->defaultVisibility;
    }

    public function suppressesPageDelivery(FederationState $state): bool
    {
        return $this->contentId->type === ContentType::PAGE
            && !$this->isEnabled($state);
    }
}
