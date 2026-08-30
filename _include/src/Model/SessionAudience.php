<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

/** Separates sessions accepted by public pages from sessions accepted by the control panel. */
enum SessionAudience: string
{
    case PUBLIC = 'public';

    case ADMIN = 'admin';
}
