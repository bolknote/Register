<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class FederationState
{
    public function __construct(
        public int                      $profileVersion,
        public FederationLifecycleState $lifecycle,
        public ?CanonicalOrigin         $canonicalOrigin,
        public CanonicalBasePath        $basePath,
        public ActorType                $siteActorType,
        public PostObjectType           $postObjectType,
        public ContentDeliveryMode      $contentMode,
        public bool                     $pagesEnabled,
        public bool                     $autoAcceptFollows,
        public int                      $createdAt,
        public ?int                     $activatedAt,
        public ?int                     $pausedAt,
        public ?int                     $decommissionedAt,
        public int                      $updatedAt,
    ) {
    }

    public function hasPublicIdentity(): bool
    {
        return in_array($this->lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED, FederationLifecycleState::DECOMMISSIONING, FederationLifecycleState::DECOMMISSIONED], true);
    }
}
