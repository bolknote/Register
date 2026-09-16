<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Register\Rose\Storage\Exception\InvalidEnvironmentException;

readonly class QueuePublisher
{
    public const int PRIORITY_NORMAL = 0;

    public const int PRIORITY_HIGH = 100;

    private const int MIN_PRIORITY = -2_147_483_648;

    private const int MAX_PRIORITY = 2_147_483_647;

    public function __construct(private \PDO $pdo, private string $dbPrefix)
    {
    }

    /**
     * @param array<mixed> $payload
     */
    public function publish(
        string $id,
        string $code,
        array $payload = [],
        ?int $availableAt = null,
        int $priority = self::PRIORITY_NORMAL,
    ): void
    {
        [$data, $driverName, $table, $now, $availableAt, $priority] = $this->prepareJob(
            $id,
            $code,
            $payload,
            $availableAt,
            $priority,
        );

        $statement = match ($driverName) {
            'mysql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON DUPLICATE KEY UPDATE generation = generation + 1, payload = VALUES(payload), '
                . 'updated_at = VALUES(updated_at), available_at = VALUES(available_at), '
                . 'priority = GREATEST(priority, VALUES(priority)), attempts = 0, last_error = NULL, failed_at = NULL'
            ),
            'sqlite' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON CONFLICT (id, code) DO UPDATE SET generation = ' . $table . '.generation + 1, payload = excluded.payload, '
                . 'updated_at = excluded.updated_at, available_at = excluded.available_at, '
                . 'priority = MAX(' . $table . '.priority, excluded.priority), attempts = 0, last_error = NULL, failed_at = NULL'
            ),
            'pgsql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON CONFLICT (id, code) DO UPDATE SET generation = ' . $table . '.generation + 1, payload = excluded.payload, '
                . 'updated_at = excluded.updated_at, available_at = excluded.available_at, '
                . 'priority = GREATEST(' . $table . '.priority, excluded.priority), attempts = 0, last_error = NULL, failed_at = NULL'
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
            'priority'     => $priority,
        ]);
    }

    /**
     * Inserts scheduled work without replacing its current generation, retry state, or backoff.
     *
     * @param array<mixed> $payload
     */
    public function publishIfAbsent(
        string $id,
        string $code,
        array $payload = [],
        ?int $availableAt = null,
        int $priority = self::PRIORITY_NORMAL,
    ): void
    {
        [$data, $driverName, $table, $now, $availableAt, $priority] = $this->prepareJob(
            $id,
            $code,
            $payload,
            $availableAt,
            $priority,
        );

        $statement = match ($driverName) {
            'mysql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON DUPLICATE KEY UPDATE priority = GREATEST(priority, VALUES(priority))'
            ),
            'sqlite' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON CONFLICT (id, code) DO UPDATE SET priority = MAX(' . $table . '.priority, excluded.priority)'
            ),
            'pgsql' => $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (id, code, payload, generation, created_at, updated_at, available_at, priority, attempts, last_error, failed_at) '
                . 'VALUES (:id, :code, :payload, 1, :created_at, :updated_at, :available_at, :priority, 0, NULL, NULL) '
                . 'ON CONFLICT (id, code) DO UPDATE SET priority = GREATEST(' . $table . '.priority, excluded.priority)'
            ),
            default => throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName)),
        };

        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the conditional queue publication query.');
        }

        $statement->execute([
            'id'           => $id,
            'code'         => $code,
            'payload'      => $data,
            'created_at'   => $now,
            'updated_at'   => $now,
            'available_at' => $availableAt,
            'priority'     => $priority,
        ]);
    }

    /**
     * @param array<mixed> $payload
     * @return array{string, string, string, int, int, int}
     */
    private function prepareJob(string $id, string $code, array $payload, ?int $availableAt, int $priority): array
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

        $now         = time();
        $availableAt ??= $now;
        if ($availableAt < 0) {
            throw new \DomainException('Availability timestamp must not be negative');
        }

        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY) {
            throw new \DomainException('Queue priority must fit a signed 32-bit integer');
        }

        return [$data, $driverName, $this->dbPrefix . 'queue', $now, $availableAt, $priority];
    }
}
