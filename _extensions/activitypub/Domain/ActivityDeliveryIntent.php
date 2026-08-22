<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

/** Whether an immutable local activity may be fanned out to remote inboxes. */
enum ActivityDeliveryIntent: string
{
    /** Historical projection or a locally visible activity that must never be broadcast. */
    case NONE = 'none';

    /** Resolve the actor's current followers when delivery records are materialized. */
    case FOLLOWERS = 'followers';

    /** Deliver an addressed activity to an explicitly selected remote inbox. */
    case DIRECT = 'direct';
}
