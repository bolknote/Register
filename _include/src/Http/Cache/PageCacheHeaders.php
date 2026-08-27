<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Internal and diagnostic headers shared by page and encoding cache layers. */
final class PageCacheHeaders
{
    public const string STATUS = 'X-Register-Page-Cache';

    /** Removed before the response leaves PHP. */
    public const string IDENTITY = 'X-Register-Page-Cache-Key';

    public const string COMPRESSION_STATUS = 'X-Register-Compression-Cache';

    private function __construct()
    {
    }
}
