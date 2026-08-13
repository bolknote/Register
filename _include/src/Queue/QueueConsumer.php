<?php
/**
 * @copyright 2023-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use Psr\Log\LoggerInterface;

class QueueConsumer
{
    /**
     * @var QueueHandlerInterface[]
     */
    private readonly array $handlers;

    public function __construct(
        private readonly \PDO            $pdo,
        private readonly string          $dbPrefix,
        private readonly LoggerInterface $logger,
        QueueHandlerInterface            ...$handlers
    ) {
        $this->handlers = $handlers;
    }

    /**
     * Fetches and processes a job from the queue.
     *
     * The queue is stored in the 'queue' table of database. Jobs are fetched and locked in a transaction.
     *
     * NOWAIT prevents parallel job processing for *different* jobs. It can be dangerous in case of several heavy jobs
     * (PHP-FPM workers can be exhausted).
     *
     */
    public function runQueue(): bool
    {
        $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driverName)) {
            throw new \RuntimeException('PDO returned an invalid driver name.');
        }

        $sql        = match ($driverName) {
            'mysql', 'pgsql' => 'SELECT * FROM ' . $this->dbPrefix . 'queue LIMIT 1 FOR UPDATE NOWAIT',
            'sqlite' => 'SELECT * FROM ' . $this->dbPrefix . 'queue LIMIT 1',
            default => throw new \RuntimeException(sprintf('Driver "%s" is not supported.', $driverName)),
        };

        $outerTransaction = $this->pdo->inTransaction();
        if ($driverName === 'sqlite') {
            $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1);
        } else {
            if (!$outerTransaction) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
        }

        if (!$outerTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $job = null;
            try {
                $statement = $this->pdo->query($sql);
                if ($statement === false) {
                    throw new \RuntimeException('Unable to prepare the queue fetch query.');
                }

                $fetchedJob = $statement->fetch(\PDO::FETCH_ASSOC);
                $job        = \is_array($fetchedJob) ? $fetchedJob : null;
            } catch (\PDOException $e) {
                $message = $e->getMessage();
                if (
                    ($driverName === 'mysql' && (str_contains($message, 'Lock wait timeout exceeded') || str_contains($message, 'NOWAIT is set')))
                    || ($driverName === 'pgsql' && (str_contains($message, 'Lock not available')))
                ) {
                    $this->logger->notice('No jobs were found due to locks in parallel process.', ['exception' => $e]);
                } else {
                    $this->logger->warning('Failed to fetch queue item: ' . $message, ['exception' => $e]);
                }
            }

            if ($job === null) {
                if (!$outerTransaction) {
                    $this->pdo->rollBack();
                }

                return false;
            }

            $jobId = $job['id'] ?? null;
            $jobCode = $job['code'] ?? null;
            $encodedPayload = $job['payload'] ?? null;
            if (!\is_string($jobId) || !\is_string($jobCode) || !\is_string($encodedPayload)) {
                throw new \UnexpectedValueException('A queue row must contain string id, code and payload fields.');
            }

            $payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload)) {
                throw new \UnexpectedValueException('A queue payload must decode to an array.');
            }

            $this->logger->notice('Found queue item', $job);

            try {
                foreach ($this->handlers as $handler) {
                    if ($handler->handle($jobId, $jobCode, $payload)) {
                        $this->logger->notice('Queue item has been processed', $job);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Throwable occurred while processing queue: ' . $e->getMessage(), ['exception' => $e]);
            }

            $statement = $this->pdo->prepare('DELETE FROM ' . $this->dbPrefix . 'queue WHERE id = :id AND code = :code');
            if ($statement === false) {
                throw new \RuntimeException('Unable to prepare the queue deletion query.');
            }

            $statement->execute([
                'id'   => $job['id'],
                'code' => $job['code'],
            ]);

            if (!$outerTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Unknown throwable occurred, do rollback: ' . $throwable->getMessage(), ['exception' => $throwable]);
            if (!$outerTransaction) {
                $this->pdo->rollBack();
            }
        }

        return true;
    }
}
