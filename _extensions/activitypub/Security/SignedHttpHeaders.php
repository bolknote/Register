<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Security;

final readonly class SignedHttpHeaders
{
    /** @param array<string, string> $headers */
    public function __construct(
        public array  $headers,
        public string $signatureBase,
    ) {
    }
}
