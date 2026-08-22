<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
        public string $displayName,
        public string $avatarUrl,
        public string $returnPath,
    ) {
    }
}
