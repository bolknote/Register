<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

final class ContentUrlCollisionException extends \RuntimeException
{
    public const string PATH_TOO_LONG = 'The complete URL exceeds the 255-byte URL history limit. Shorten the address or its parent addresses.';
}
