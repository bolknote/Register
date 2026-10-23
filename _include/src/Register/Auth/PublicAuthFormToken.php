<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Config\StringProxy;

/** Shared sign-in form freshness token; mutations also require same-origin browser evidence. */
final readonly class PublicAuthFormToken
{
    private const int LIFETIME = 86400;

    /** Keep guest page bodies and their ETags stable while retaining short-lived form credentials. */
    private const int ISSUE_INTERVAL = 3600;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private StringProxy $secret, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function issue(?int $now = null): string
    {
        return $this->issueWithExpiry($now)['token'];
    }

    /** @return array{token:string,expires:int} */
    public function issueWithExpiry(?int $now = null): array
    {
        $timestamp = $now ?? ($this->clock)();
        $timestamp = intdiv($timestamp, self::ISSUE_INTERVAL) * self::ISSUE_INTERVAL;

        return ['token' => $timestamp . '.' . $this->signature($timestamp), 'expires' => $timestamp + self::LIFETIME];
    }

    public function matches(string $candidate, ?int $now = null): bool
    {
        if (preg_match('/^([1-9][0-9]{8,11})\.([0-9a-f]{64})$/D', $candidate, $matches) !== 1) {
            return false;
        }

        $timestamp = (int)$matches[1];
        $now ??= ($this->clock)();
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
