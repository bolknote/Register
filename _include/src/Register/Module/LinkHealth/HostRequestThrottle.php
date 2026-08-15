<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

/** Applies a durable, independent request cadence to every external host. */
final readonly class HostRequestThrottle
{
    public const int INTERVAL_SECONDS = 2;

    private const string SERVICE_PREFIX = 'host:';

    private const int RETENTION_SECONDS = 30 * 86400;

    private RemoteRequestThrottle $throttle;

    public function __construct(\PDO $pdo, string $dbPrefix)
    {
        $this->throttle = new RemoteRequestThrottle($pdo, $dbPrefix);
    }

    /** @return int|null Earliest retry timestamp, or null when this caller acquired the slot. */
    public function claim(string $url, int $now): ?int
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new \InvalidArgumentException('A throttled remote URL must contain a host.');
        }

        return $this->throttle->claim(
            self::SERVICE_PREFIX . substr(hash('sha256', strtolower(trim($host, '[]'))), 0, 27),
            self::INTERVAL_SECONDS,
            $now,
        );
    }

    public function prune(int $now): void
    {
        if ($now < self::RETENTION_SECONDS) {
            return;
        }

        $this->throttle->prune(self::SERVICE_PREFIX, $now - self::RETENTION_SECONDS);
    }
}
