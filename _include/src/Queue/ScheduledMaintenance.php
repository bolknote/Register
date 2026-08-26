<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Register\Backup\BackupQueueHandler;
use Register\Content\ContentPublicationQueueHandler;
use Register\Content\ContentPublicationScheduler;
use Register\Core\Comment\Antispam\SpamMaintenance;
use Register\Core\Comment\Antispam\SpamMaintenanceQueueHandler;

final readonly class ScheduledMaintenance
{
    public const int INTERVAL_SECONDS = 3600;

    private const string CONFIG_KEY = 'REGISTER_LAST_MAINTENANCE';

    private const int INITIAL_QUEUE_DELAY_SECONDS = 1;

    /** @var list<ScheduledMaintenanceTaskInterface> */
    private array $additionalTasks;

    public function __construct(
        private \PDO          $pdo,
        private string        $dbPrefix,
        private QueuePublisher $queuePublisher,
        private ContentPublicationScheduler $publicationScheduler,
        private bool           $automaticBackupEnabled,
        ScheduledMaintenanceTaskInterface ...$additionalTasks,
    ) {
        $this->additionalTasks = array_values($additionalTasks);
    }

    /** Ensures latency-sensitive scheduled publication has one durable queue trigger. */
    public function scheduleRequestWork(?int $now = null, ?QueueExecutionBudget $budget = null): void
    {
        $now ??= time();
        $budget ??= new QueueExecutionBudget(30.0);
        $budget->checkpoint(0.02);
        if (!$this->publicationScheduler->hasDue($now)) {
            return;
        }

        $budget->checkpoint(0.02);
        $this->queuePublisher->publishIfAbsent(
            ContentPublicationQueueHandler::JOB_ID,
            ContentPublicationQueueHandler::CODE,
            availableAt: $now,
        );
    }

    /** Read-only preflight used to avoid contending on the global runner lease while idle. */
    public function hasDueWork(?int $now = null, ?QueueExecutionBudget $budget = null): bool
    {
        $now ??= time();
        $budget ??= new QueueExecutionBudget(30.0);
        $budget->checkpoint(0.02);
        if ($this->publicationScheduler->hasDue($now)) {
            return true;
        }

        $budget->checkpoint(0.02);
        return $this->lastMaintenance($now) <= $now - self::INTERVAL_SECONDS;
    }

    public function runIfDue(?int $now = null, ?QueueExecutionBudget $budget = null): bool
    {
        $now ??= time();
        $budget ??= new QueueExecutionBudget(30.0);
        $budget->checkpoint(0.05);
        $statement = $this->pdo->prepare(
            'SELECT value FROM ' . $this->dbPrefix . 'config WHERE name = :name'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance schedule query.');
        }

        $statement->execute(['name' => self::CONFIG_KEY]);
        $lastValue = $statement->fetchColumn();
        if (!\is_string($lastValue)) {
            throw new \UnexpectedValueException('The maintenance schedule is missing from configuration.');
        }

        $lastMaintenance = $this->parseLastMaintenance($lastValue);
        if ($lastMaintenance > $now - self::INTERVAL_SECONDS) {
            return false;
        }

        foreach (SpamMaintenance::OPERATIONS as $operation) {
            $budget->checkpoint(0.02);
            $this->queuePublisher->publishIfAbsent(
                $operation,
                SpamMaintenanceQueueHandler::CODE,
                ['scheduled_at' => $now],
                $now + self::INITIAL_QUEUE_DELAY_SECONDS,
            );
        }

        if ($this->automaticBackupEnabled) {
            $budget->checkpoint(0.02);
            $this->queuePublisher->publishIfAbsent(
                BackupQueueHandler::JOB_ID,
                BackupQueueHandler::CODE,
                availableAt: $now + self::INITIAL_QUEUE_DELAY_SECONDS,
            );
        }

        foreach ($this->additionalTasks as $additionalTask) {
            $budget->checkpoint(0.05);
            $additionalTask->schedule($now, $budget);
        }

        $budget->checkpoint(0.02);
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . 'config SET value = :now WHERE name = :name AND value = :previous_value'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance completion query.');
        }

        $statement->execute([
            'now'            => (string)$now,
            'name'           => self::CONFIG_KEY,
            'previous_value' => $lastValue,
        ]);

        return true;
    }

    private function lastMaintenance(int $now): int
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The maintenance timestamp must be positive.');
        }

        $statement = $this->pdo->prepare(
            'SELECT value FROM ' . $this->dbPrefix . 'config WHERE name = :name'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance preflight query.');
        }

        $statement->execute(['name' => self::CONFIG_KEY]);
        return $this->parseLastMaintenance($statement->fetchColumn());
    }

    private function parseLastMaintenance(mixed $value): int
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('The maintenance schedule is missing from configuration.');
        }

        $lastMaintenance = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($lastMaintenance === false) {
            throw new \UnexpectedValueException('The maintenance schedule is invalid.');
        }

        return $lastMaintenance;
    }
}
