<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Config\StringProxy;

/** Stateless CSRF token for unauthenticated sign-in forms. */
final readonly class PublicAuthFormToken
{
    private const int LIFETIME = 86400;

    public function __construct(private StringProxy $secret)
    {
    }

    public function issue(?int $now = null): string
    {
        $timestamp = $now ?? time();

        return $timestamp . '.' . $this->signature($timestamp);
    }

    public function matches(string $candidate, ?int $now = null): bool
    {
        if (preg_match('/^([1-9][0-9]{8,11})\.([0-9a-f]{64})$/D', $candidate, $matches) !== 1) {
            return false;
        }
        $timestamp = (int)$matches[1];
        $now ??= time();
        if ($timestamp > $now + 300 || $timestamp < $now - self::LIFETIME) {
            return false;
        }

        return hash_equals($this->signature($timestamp), $matches[2]);
    }

    private function signature(int $timestamp): string
    {
        return hash_hmac('sha256', "public-auth\0form\0" . $timestamp, $this->secret->get());
    }
}
