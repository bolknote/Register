<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum FederationLifecycleState: string
{
    case INSTALLED = 'installed';
    case ACTIVE = 'active';
    case PAUSED = 'paused';

    case DECOMMISSIONING = 'decommissioning';

    case DECOMMISSIONED = 'decommissioned';
}
