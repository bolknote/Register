<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

enum LinkHealthStatus: string
{
    case UNKNOWN = 'unknown';

    case HEALTHY = 'healthy';

    case RESTRICTED = 'restricted';

    case SUSPECT = 'suspect';

    case BROKEN = 'broken';

    case BLOCKED = 'blocked';

    case IGNORED = 'ignored';

    case SKIPPED = 'skipped';
}
