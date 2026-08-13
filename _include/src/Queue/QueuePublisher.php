<?php
/**
 * @copyright 2023-2024 Roman Parpalak
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
    public function publish(string $id, string $code, array $payload = []): void
    {
        if (\strlen($id) > 80) {
            throw new \DomainException('Id length must not exceed 80 characters');
        }

        if (\strlen($code) > 80) {
            throw new \DomainException('Code length must not exceed 80 characters');
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

        switch ($driverName) {
            case 'mysql':
                // There is a wierd behaviour of INSERT IGNORE in MySQL. Unlike Postgres, INSERT IGNORE waits
                // for releasing a lock even if the row was just locked with SELECT ... FOR UPDATE and not modified yet.
                // Moreover, there is no INSERT ... NOWAIT operator. Let's make it by hands.
                $this->pdo->exec('SET innodb_lock_wait_timeout = 0;');
                $statement = $this->pdo->prepare('INSERT IGNORE INTO ' . $this->dbPrefix . 'queue (id, code, payload) VALUES (:id, :code, :payload)');
                break;

            case 'sqlite':
                $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1);
            case 'pgsql':
                $statement = $this->pdo->prepare('INSERT INTO ' . $this->dbPrefix . 'queue (id, code, payload) VALUES (:id, :code, :payload) ON CONFLICT DO NOTHING');
                break;

            default:
                throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName));
        }

        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue publication query.');
        }

        try {
            $statement->execute([
                'id'      => $id,
                'code'    => $code,
                'payload' => $data,
            ]);
        } catch (\PDOException $pdoException) {
            if (
                (1205 === (int)($pdoException->errorInfo[1] ?? 0) && $driverName === 'mysql')
                || (5 === (int)($pdoException->errorInfo[1] ?? 0) && $driverName === 'sqlite') // SQLSTATE[HY000]: General error: 5 database is locked
            ) {
                // Cannot insert a new item while the existing one is locked;
                return;
            }

            throw $pdoException;
        } finally {
            switch ($driverName) {
                case 'mysql':
                    $this->pdo->exec('SET innodb_lock_wait_timeout = 5;');
                    break;

                case 'sqlite':
                    $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
                    break;
            }
        }
    }
}
