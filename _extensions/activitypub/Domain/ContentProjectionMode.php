<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum ContentProjectionMode: string
{
    /** A real editorial change; posts are eligible for follower delivery. */
    case LIVE_CHANGE = 'live_change';

    /** Import existing history into public collections without sending it to followers. */
    case HISTORY_ONLY = 'history_only';

    public function deliveryIntent(bool $suppressDelivery): ActivityDeliveryIntent
    {
        return $this === self::LIVE_CHANGE && !$suppressDelivery
            ? ActivityDeliveryIntent::FOLLOWERS
            : ActivityDeliveryIntent::NONE;
    }
}
