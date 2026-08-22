<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum ContentProjectionAction: string
{
    case SKIPPED = 'skipped';
    case UNCHANGED = 'unchanged';
    case CREATED = 'created';
    case UPDATED = 'updated';
    case REPLACED = 'replaced';
    case TOMBSTONED = 'tombstoned';
}
