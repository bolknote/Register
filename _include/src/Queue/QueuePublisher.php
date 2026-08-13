<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license MIT
 * @package S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use S2\Rose\Storage\Exception\InvalidEnvironmentException;

readonly class QueuePublisher
{
    public function __construct(private \PDO $pdo, private string $dbPrefix)
    {
    }

    /**
     * @param array<mixed> $payload
     */
    public function publish(string $id, string $code, array $payload = [], ?int $availableAt = null): void
    {
        if (\strlen($id) > 80) {
            throw new \DomainException('Id length must not exceed 80 characters');
        }

        if (\strlen($code) > 80) {
            throw new \DomainException('Code length must not exceed 80 characters');
        }

        if ($code === '') {
            throw new \DomainException('Code must not be empty');
        }

        try {
            $data = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \InvalidArgumentException($jsonException->getMessage(), 0, $jsonException);
        }

        $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driverName)) {
            throw new InvalidEnvironmentException('PDO returned an invalid driver name.');
        }

        $table       = $this->dbPrefix . 'queue';
        $now         = time();
        $availableAt ??= $now;

        if ($availableAt < 0) {
            throw new \DomainException('Availability timestamp must not be negative');
        }

        $statement = match ($driverName) {
            'mysql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, 0, NULL, NULL) '
                . 'ON DUPLICATE KEY UPDATE generation = generation + 1, payload = VALUES(payload), '
                . 'updated_at = VALUES(updated_at), available_at = VALUES(available_at), attempts = 0, last_error = NULL, failed_at = NULL'
            ),
            'sqlite', 'pgsql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, 0, NULL, NULL) '
                . 'ON CONFLICT (id, code) DO UPDATE SET generation = ' . $table . '.generation + 1, payload = excluded.payload, '
                . 'updated_at = excluded.updated_at, available_at = excluded.available_at, attempts = 0, last_error = NULL, failed_at = NULL'
            ),
            default => throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName)),
        };

        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue publication query.');
        }

        $statement->execute([
            'id'           => $id,
            'code'         => $code,
            'payload'      => $data,
            'created_at'   => $now,
            'updated_at'   => $now,
            'available_at' => $availableAt,
        ]);
    }
}
