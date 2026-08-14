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

    private const int MAX_CLAIM_ATTEMPTS = 10;

    public function __construct(
        private \PDO   $pdo,
        private string $dbPrefix,
    ) {
    }

    /**
     * Atomically claims the current request slot.
     *
     * @return int|null Earliest retry timestamp, or null when this caller acquired the slot.
     */
    public function claim(int $now): ?int
    {
        if ($now < 0 || $now > PHP_INT_MAX - self::INTERVAL_SECONDS) {
            throw new \InvalidArgumentException('A Wayback throttle timestamp is out of range.');
        }

        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; ++$attempt) {
            $nextRequestAt = $this->nextRequestAt();
            if ($nextRequestAt > $now) {
                return $nextRequestAt;
            }

            $statement = $this->pdo->prepare(
                'UPDATE ' . $this->dbPrefix . Manifest::THROTTLE_TABLE
                . ' SET next_request_at = :next_request_at'
                . ' WHERE service = :service AND next_request_at = :previous_request_at'
            );
            if ($statement === false) {
                throw new \RuntimeException('Unable to prepare the Wayback throttle claim.');
            }

            $statement->execute([
                'next_request_at'     => $now + self::INTERVAL_SECONDS,
                'service'             => self::SERVICE,
                'previous_request_at' => $nextRequestAt,
            ]);
            if ($statement->rowCount() === 1) {
                return null;
            }
        }

        throw new \RuntimeException('Unable to claim the Wayback request throttle after repeated contention.');
    }

    private function nextRequestAt(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT next_request_at FROM ' . $this->dbPrefix . Manifest::THROTTLE_TABLE
            . ' WHERE service = :service'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the Wayback throttle query.');
        }

        $statement->execute(['service' => self::SERVICE]);
        $value = $statement->fetchColumn();
        if (!\is_int($value) && (!\is_string($value) || !ctype_digit($value))) {
            throw new \UnexpectedValueException('The Wayback request throttle is missing or invalid.');
        }

        return (int)$value;
    }
}
