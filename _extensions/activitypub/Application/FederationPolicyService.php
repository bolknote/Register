<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Domain\FederationPolicy;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;

/** Changes defaults only; it never projects, updates, or deletes content in bulk. */
final readonly class FederationPolicyService
{
    public function __construct(private FederationStateRepository $stateRepository)
    {
    }

    public function save(FederationPolicy $policy, ?int $now = null): void
    {
        $this->stateRepository->updatePolicy($policy, $now ?? time());
    }
}
