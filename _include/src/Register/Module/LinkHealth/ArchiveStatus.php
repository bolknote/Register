<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

enum ArchiveStatus: string
{
    case NOT_APPLICABLE = 'n/a';

    case UNCHECKED = 'unchecked';

    case AVAILABLE = 'available';

    case MISSING = 'missing';

    case ERROR = 'error';
}
