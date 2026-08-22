<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

use Register\Extension\activitypub\Infrastructure\FederationStateRepository;

final readonly class FederationUrlGeneratorFactory
{
    public function __construct(private FederationStateRepository $stateRepository)
    {
    }

    public function create(): FederationUrlGenerator
    {
        $state = $this->stateRepository->state();
        if (!$state->canonicalOrigin instanceof CanonicalOrigin) {
            throw new \LogicException('The ActivityPub canonical origin has not been frozen.');
        }

        return new FederationUrlGenerator($state->canonicalOrigin, $state->basePath);
    }
}
