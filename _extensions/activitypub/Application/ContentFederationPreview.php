<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Domain\ContentProjectionAction;

/** Read-only result produced through the same document builder as a live projection. */
final readonly class ContentFederationPreview
{
    /**
     * @param array<string, mixed>|null $document
     * @param list<string>              $provisionalFields
     */
    public function __construct(
        public ContentProjectionAction $action,
        public ?array                  $document,
        public string                  $canonicalJson,
        public string                  $ownerHandle,
        public string                  $canonicalUrl,
        public bool                    $contentPublished,
        public bool                    $federationEnabled,
        public array                   $provisionalFields,
    ) {
    }
}
