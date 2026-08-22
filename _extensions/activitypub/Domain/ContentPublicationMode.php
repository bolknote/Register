<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

/** Per-content publication decision; inherit preserves the installation-wide policy. */
enum ContentPublicationMode: string
{
    case INHERIT = 'inherit';
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
}
