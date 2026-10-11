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
     * Continues work only while the queued payload still matches the worker's snapshot.
     * The expected payload must identify the operation, including any operation token.
     * A superseded, removed or failed job is left untouched.
     *
     * @param array<mixed> $expectedPayload
     * @param array<mixed> $payload
     */
    public function publishIfPayloadMatches(
        string $id,
        string $code,
        array $expectedPayload,
        array $payload,
        ?int $availableAt = null,
        int $priority = self::PRIORITY_NORMAL,
    ): bool {
        [$data, $driverName, $table, $now, $availableAt, $priority] = $this->prepareJob($id, $code, $payload, $availableAt, $priority);
        $expectedData = $this->encodePayload($expectedPayload);
        [$maximumPriority, $payloadMatch] = match ($driverName) {
            'mysql' => ['GREATEST(priority, CAST(:priority AS SIGNED))', 'CAST(payload AS BINARY) = CAST(:expected_payload AS BINARY)'],
            'sqlite' => ['MAX(priority, CAST(:priority AS INTEGER))', 'payload = :expected_payload'],
            'pgsql' => ['GREATEST(priority, CAST(:priority AS INTEGER))', 'payload = :expected_payload'],
            default => throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName)),
        };
        $statement = $this->pdo->prepare(
            'UPDATE ' . $table . ' SET generation = generation + 1, payload = :payload, '
            . 'updated_at = :updated_at, available_at = :available_at, priority = ' . $maximumPriority . ', '
            . 'attempts = 0, last_error = NULL '
            . 'WHERE id = :id AND code = :code AND failed_at IS NULL AND ' . $payloadMatch
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue continuation query.');
        }

        $statement->execute([
            'id'               => $id,
            'code'             => $code,
            'expected_payload' => $expectedData,
            'payload'          => $data,
            'updated_at'       => $now,
            'available_at'     => $availableAt,
            'priority'         => $priority,
        ]);

        return $statement->rowCount() === 1;
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

        $data = $this->encodePayload($payload);

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

    /** @param array<mixed> $payload */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \InvalidArgumentException($jsonException->getMessage(), 0, $jsonException);
        }
    }
}
