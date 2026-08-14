<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

enum LinkKind: string
{
    case EXTERNAL = 'external';

    case LOCAL = 'local';

    case ARCHIVE = 'archive';
}
