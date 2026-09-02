<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Raised when an encrypted volatile-cache entry cannot be authenticated. */
final class CacheIntegrityException extends \RuntimeException
{
}
