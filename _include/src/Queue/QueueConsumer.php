<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use Psr\Log\LoggerInterface;

final readonly class QueueConsumer
{
    public const int MAX_ATTEMPTS = 5;

    private const int INITIAL_RETRY_DELAY = 30;

    private const int MAX_RETRY_DELAY = 3600;

    public function __construct(
        private \PDO                $pdo,
        private string              $dbPrefix,
        private LoggerInterface     $logger,
        private QueueHandlerRegistry $handlerRegistry,
    ) {
    }

    /**
     * Fetches and attempts one due job. The handler deliberately runs without a queue transaction.
     *
     * Returns true when a job was attempted, including a failed attempt, and false when no due job exists.
     *
     * @phpstan-impure Consumes and deletes a database row and may enqueue follow-up jobs.
     */
    public function runQueue(?int $now = null, ?QueueExecutionBudget $budget = null): bool
    {
        $now ??= time();
        $budget ??= new QueueExecutionBudget(30.0);
        if (!$budget->canStart()) {
            return false;
        }

        $job = $this->fetchRunnableJob($now, $budget);
        if ($job === null) {
            return false;
        }

        $jobId          = $this->stringField($job, 'id');
        $jobCode        = $this->stringField($job, 'code');
        $encodedPayload = $this->stringField($job, 'payload');
        $generation     = $this->integerField($job, 'generation');
        $attempts       = $this->integerField($job, 'attempts');
        $hadTransaction = $this->pdo->inTransaction();

        $context = [
            'id'         => $jobId,
            'code'       => $jobCode,
            'generation' => $generation,
            'attempt'    => $attempts + 1,
        ];
        try {
            $payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload)) {
                throw new \UnexpectedValueException('A queue payload must decode to an array.');
            }

            $handler = $this->handlerRegistry->get($jobCode);
            if (!$budget->canStart($handler->minimumExecutionTime())) {
                return false;
            }

            $this->logger->notice('Queue item is being processed.', $context);
            $handler->handle($jobId, $jobCode, $payload, $budget);

            if (!$hadTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                throw new \RuntimeException('A queue handler left a database transaction open.');
            }

            $deleted = $this->acknowledge($jobId, $jobCode, $generation);
            if ($deleted) {
                $this->logger->notice('Queue item has been processed.', $context);
            } else {
                $this->logger->notice('Queue item was republished while it was being processed.', $context);
            }
        } catch (QueueTimeBudgetExceeded $exception) {
            if (!$hadTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->deferForBudget($jobId, $jobCode, $generation, $now, $exception);
        } catch (\Throwable $throwable) {
            if (!$hadTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->scheduleRetry($jobId, $jobCode, $generation, $attempts, $now, $throwable);
        }

        return true;
    }

    private function deferForBudget(
        string                  $id,
        string                  $code,
        int                     $generation,
        int                     $now,
        QueueTimeBudgetExceeded $exception,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . 'queue SET updated_at = :updated_at, available_at = :available_at '
            . 'WHERE id = :id AND code = :code AND generation = :generation'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue budget deferral query.', 0, $exception);
        }

        $statement->execute([
            'updated_at'   => $now,
            'available_at' => $now + 1,
            'id'           => $id,
            'code'         => $code,
            'generation'   => $generation,
        ]);

        $context = [
            'id'         => $id,
            'code'       => $code,
            'generation' => $generation,
        ];
        if ($statement->rowCount() === 1) {
            $this->logger->notice('Queue item was deferred because the execution budget was exhausted.', $context);
        } else {
            $this->logger->notice('Queue item exhausted its budget, but a newer generation is already available.', $context);
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchRunnableJob(int $now, QueueExecutionBudget $budget): ?array
    {
        $parameters    = ['now' => $now];
        $codeFilter    = '';
        $excludedCodes = $this->handlerRegistry->codesExceedingBudget($budget);
        if ($excludedCodes !== []) {
            $placeholders = [];
            foreach ($excludedCodes as $index => $code) {
                $parameterName              = 'excluded_code_' . $index;
                $placeholders[]              = ':' . $parameterName;
                $parameters[$parameterName] = $code;
            }

            $codeFilter = 'AND code NOT IN (' . implode(', ', $placeholders) . ') ';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, code, payload, generation, attempts FROM ' . $this->dbPrefix . 'queue '
            . 'WHERE failed_at IS NULL AND available_at <= :now '
            . $codeFilter
            . 'ORDER BY available_at, created_at, id, code LIMIT 1'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue fetch query.');
        }

        $statement->execute($parameters);
        $job = $statement->fetch(\PDO::FETCH_ASSOC);
        return \is_array($job) ? $job : null;
    }

    private function acknowledge(string $id, string $code, int $generation): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->dbPrefix . 'queue WHERE id = :id AND code = :code AND generation = :generation'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue acknowledgement query.');
        }

        $statement->execute([
            'id'         => $id,
            'code'       => $code,
            'generation' => $generation,
        ]);

        return $statement->rowCount() === 1;
    }

    private function scheduleRetry(
        string     $id,
        string     $code,
        int        $generation,
        int        $previousAttempts,
        int        $now,
        \Throwable $throwable,
    ): void {
        $attempts    = $previousAttempts + 1;
        $failedAt    = $attempts >= self::MAX_ATTEMPTS ? $now : null;
        $availableAt = $failedAt === null ? $now + $this->retryDelay($previousAttempts) : $now;
        $error       = mb_substr($throwable::class . ': ' . $throwable->getMessage(), 0, 4000);

        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . 'queue SET attempts = :attempts, updated_at = :updated_at, '
            . 'available_at = :available_at, last_error = :last_error, failed_at = :failed_at '
            . 'WHERE id = :id AND code = :code AND generation = :generation'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the queue retry query.', 0, $throwable);
        }

        $statement->execute([
            'attempts'     => $attempts,
            'updated_at'   => $now,
            'available_at' => $availableAt,
            'last_error'   => $error,
            'failed_at'    => $failedAt,
            'id'           => $id,
            'code'         => $code,
            'generation'   => $generation,
        ]);

        $context = [
            'id'         => $id,
            'code'       => $code,
            'generation' => $generation,
            'attempt'    => $attempts,
            'exception'  => $throwable,
        ];
        if ($statement->rowCount() === 0) {
            $this->logger->notice('Queue item failed, but a newer generation is already available.', $context);
        } elseif ($failedAt !== null) {
            $this->logger->error('Queue item has exhausted its retry limit.', $context);
        } else {
            $context['available_at'] = $availableAt;
            $this->logger->warning('Queue item failed; retry has been scheduled.', $context);
        }
    }

    private function retryDelay(int $previousAttempts): int
    {
        return min(self::MAX_RETRY_DELAY, self::INITIAL_RETRY_DELAY << min($previousAttempts, 7));
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('A queue row must contain a string "%s" field.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integerField(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!\is_int($value) && (!\is_string($value) || !ctype_digit($value))) {
            throw new \UnexpectedValueException(\sprintf('A queue row must contain an integer "%s" field.', $field));
        }

        return (int)$value;
    }
}
