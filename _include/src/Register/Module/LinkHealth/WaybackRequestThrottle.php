<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

/** Coordinates Wayback requests across queue workers and application nodes without sleeping. */
final readonly class WaybackRequestThrottle
{
    public const string SERVICE = 'wayback';

    public const int INTERVAL_SECONDS = 15;

    public const int ERROR_BACKOFF_SECONDS = 60;

    public const int RATE_LIMIT_BACKOFF_SECONDS = 300;

    private RemoteRequestThrottle $throttle;

    public function __construct(\PDO $pdo, string $dbPrefix)
    {
        $this->throttle = new RemoteRequestThrottle($pdo, $dbPrefix);
    }

    /**
     * Atomically claims the current request slot.
     *
     * @return int|null Earliest retry timestamp, or null when this caller acquired the slot.
     */
    public function claim(int $now): ?int
    {
        return $this->throttle->claim(self::SERVICE, self::INTERVAL_SECONDS, $now);
    }

    public function backOff(int $now, bool $rateLimited): void
    {
        if ($now < 0) {
            throw new \InvalidArgumentException('A Wayback backoff time cannot be negative.');
        }

        $delay = $rateLimited ? self::RATE_LIMIT_BACKOFF_SECONDS : self::ERROR_BACKOFF_SECONDS;
        $this->throttle->deferUntil(self::SERVICE, $now + $delay);
    }
}
