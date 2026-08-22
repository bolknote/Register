<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum InboxState: string
{
    case RECEIVED = 'received';
    case DELAYED = 'delayed';
    case IN_FLIGHT = 'in_flight';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case FAILED = 'failed';
}
