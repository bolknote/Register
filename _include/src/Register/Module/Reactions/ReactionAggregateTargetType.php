<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

enum ReactionAggregateTargetType: string
{
    case POST = 'post';

    case PAGE = 'page';

    case COMMENT = 'comment';

    /** A private ActivityPub reader Note; never exposed through the public site reaction UI. */
    case ACTIVITYPUB_NOTE = 'activitypub_note';
}
