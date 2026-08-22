<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;

/** Runs the automatic full backup as durable, observable queue work. */
final readonly class BackupQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'register_automatic_backup';

    public const string JOB_ID = 'daily';

    private const int DISABLED_RECHECK_SECONDS = 3600;

    public function __construct(
        private BackupManager  $backupManager,
        private QueuePublisher $queuePublisher,
        private bool           $enabled,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 4.0;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($id !== self::JOB_ID || $code !== self::CODE || $payload !== []) {
            throw new \InvalidArgumentException('Invalid automatic-backup job.');
        }

        if (!$this->enabled) {
            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now    = time();
        $backup = $this->backupManager->createIfDue($now) ?? $this->backupManager->latest();
        $nextAt = $backup instanceof BackupFile
            ? max($now + 1, $backup->createdAt + BackupManager::AUTOMATIC_INTERVAL_SECONDS)
            : $now + self::DISABLED_RECHECK_SECONDS;

        $budget->checkpoint(0.02);
        $this->queuePublisher->publish(self::JOB_ID, self::CODE, availableAt: $nextAt);
    }
}
