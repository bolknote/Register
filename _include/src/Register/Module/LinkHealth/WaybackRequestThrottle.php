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
}
