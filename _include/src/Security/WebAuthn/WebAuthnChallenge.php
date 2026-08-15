<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

final readonly class WebAuthnChallenge
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string  $token,
        public string  $challenge,
        public ?int    $userId,
        public ?string $sessionHash,
        public array   $context,
        public int     $expiresAt,
    ) {
    }
}
