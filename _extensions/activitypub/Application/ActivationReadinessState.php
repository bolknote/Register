<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

enum ActivationReadinessState: string
{
    case CHECKING = 'checking';
    case READY = 'ready';
    case FAILED = 'failed';
    case ACTIVATED = 'activated';
    case SUPERSEDED = 'superseded';
}
