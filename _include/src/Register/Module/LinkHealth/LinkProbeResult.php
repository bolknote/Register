<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkProbeResult
{
    public const string ERROR_TIMEOUT = 'timeout';

    public const string ERROR_DNS = 'dns';

    public const string ERROR_UNSAFE = 'unsafe';

    public const string ERROR_REDIRECT = 'redirect';

    public const string ERROR_NETWORK = 'network';

    public function __construct(
        public string  $effectiveUrl,
        public int     $statusCode = 0,
        public ?string $error = null,
        public ?string $errorReason = null,
    ) {
    }
}
