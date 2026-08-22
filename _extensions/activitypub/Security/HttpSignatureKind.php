<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

enum HttpSignatureKind: string
{
    case LEGACY = 'legacy';
    case RFC_9421 = 'rfc9421';
}
