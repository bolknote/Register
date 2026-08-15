<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Rose\Storage\Exception\InvalidEnvironmentException;

/** Coordinates remote-request slots across queue workers and application nodes without sleeping. */
final readonly class RemoteRequestThrottle
{
    private const int MAX_CLAIM_ATTEMPTS = 10;

    public function __construct(
        private \PDO   $pdo,
        private string $dbPrefix,
    ) {
    }

    /** @return int|null Earliest retry timestamp, or null when this caller acquired the slot. */
    public function claim(string $service, int $interval, int $now): ?int
    {
        $this->validate($service, $interval, $now);
        $this->ensureService($service);

        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; ++$attempt) {
            $nextRequestAt = $this->nextRequestAt($service);
            if ($nextRequestAt > $now) {
                return $nextRequestAt;
            }

            $statement = $this->pdo->prepare(
                'UPDATE ' . $this->table()
                . ' SET next_request_at = :next_request_at'
                . ' WHERE service = :service AND next_request_at = :previous_request_at'
            );
            if ($statement === false) {
                throw new \RuntimeException('Unable to prepare the remote-request throttle claim.');
            }

            $statement->execute([
                'next_request_at'     => $now + $interval,
                'service'             => $service,
                'previous_request_at' => $nextRequestAt,
            ]);
            if ($statement->rowCount() === 1) {
                return null;
            }
        }

        throw new \RuntimeException('Unable to claim a remote-request throttle after repeated contention.');
    }

    public function prune(string $servicePrefix, int $before): void
    {
        if ($servicePrefix === '' || \strlen($servicePrefix) > 32 || $before < 0) {
            throw new \InvalidArgumentException('Remote-request throttle cleanup arguments are invalid.');
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->table()
            . ' WHERE service LIKE :service_prefix AND next_request_at < :before'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare remote-request throttle cleanup.');
        }

        $statement->execute([
            'service_prefix' => $servicePrefix . '%',
            'before'         => $before,
        ]);
    }

    private function ensureService(string $service): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driver)) {
            throw new InvalidEnvironmentException('PDO returned an invalid driver name.');
        }

        $statement = match ($driver) {
            'mysql' => $this->pdo->prepare(
                'INSERT INTO ' . $this->table() . ' (service, next_request_at) VALUES (:service, 0)'
                . ' ON DUPLICATE KEY UPDATE service = VALUES(service)'
            ),
            'sqlite', 'pgsql' => $this->pdo->prepare(
                'INSERT INTO ' . $this->table() . ' (service, next_request_at) VALUES (:service, 0)'
                . ' ON CONFLICT (service) DO NOTHING'
            ),
            default => throw new InvalidEnvironmentException(\sprintf('Driver "%s" is not supported.', $driver)),
        };
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare remote-request throttle initialization.');
        }

        $statement->execute(['service' => $service]);
    }

    private function nextRequestAt(string $service): int
    {
        $statement = $this->pdo->prepare(
            'SELECT next_request_at FROM ' . $this->table() . ' WHERE service = :service'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the remote-request throttle query.');
        }

        $statement->execute(['service' => $service]);
        $value = $statement->fetchColumn();
        if (!\is_int($value) && (!\is_string($value) || !ctype_digit($value))) {
            throw new \UnexpectedValueException('A remote-request throttle is missing or invalid.');
        }

        return (int)$value;
    }

    private function validate(string $service, int $interval, int $now): void
    {
        if ($service === ''
            || \strlen($service) > 32
            || $interval < 1
            || $now < 0
            || $now > PHP_INT_MAX - $interval
        ) {
            throw new \InvalidArgumentException('Remote-request throttle arguments are invalid.');
        }
    }

    private function table(): string
    {
        return $this->dbPrefix . Manifest::THROTTLE_TABLE;
    }
}
