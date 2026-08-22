<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum LocalActorState: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case MOVED = 'moved';
    case DEACTIVATED = 'deactivated';
    case TOMBSTONED = 'tombstoned';
}
