<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

use Register\Content\ContentId;
use Register\Content\ContentType;

/** Validated editor values before a newly-created content row has its numeric identifier. */
final readonly class ContentFederationSettingsDraft
{
    public function __construct(
        public ContentType             $contentType,
        public ContentPublicationMode  $publicationMode,
        public ?ContentDeliveryMode    $deliveryMode,
        public ?PostObjectType         $postObjectType,
        public ?string                 $visibility,
        public string                  $summary,
        public ?string                 $language,
    ) {
        $this->bind(new ContentId($this->contentType, 1));
    }

    public function bind(ContentId $contentId): ContentFederationSettings
    {
        if ($contentId->type !== $this->contentType) {
            throw new \InvalidArgumentException('ActivityPub editor settings were bound to another content type.');
        }

        return new ContentFederationSettings(
            $contentId,
            $this->publicationMode,
            $this->deliveryMode,
            $this->postObjectType,
            $this->visibility,
            $this->summary,
            $this->language,
        );
    }
}
