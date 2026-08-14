<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\QueueExecutionBudget;

final readonly class SpamMaintenanceQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'spam_maintenance';

    public const int BATCH_SIZE = 100;

    private const int CONTINUATION_DELAY_SECONDS = 1;

    public function __construct(
        private SpamMaintenance $maintenance,
        private QueuePublisher  $queuePublisher,
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
        return 0.05;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($code !== self::CODE) {
            throw new \InvalidArgumentException('Unexpected antispam maintenance queue code.');
        }

        $scheduledAt = $payload['scheduled_at'] ?? null;
        if (!\is_int($scheduledAt) || $scheduledAt < 0) {
            throw new \UnexpectedValueException('Antispam maintenance payload must contain a valid schedule timestamp.');
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $deleted = $this->maintenance->runOperation($id, $scheduledAt, self::BATCH_SIZE);
        if ($deleted > 0) {
            $budget->checkpoint(0.02);
            $this->queuePublisher->publish(
                $id,
                self::CODE,
                $payload,
                time() + self::CONTINUATION_DELAY_SECONDS,
            );
        }
    }
}
