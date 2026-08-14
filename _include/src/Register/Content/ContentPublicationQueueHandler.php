<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;

/** Publishes due content in small, independently retryable batches. */
final readonly class ContentPublicationQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'register_publish_due';

    public const string JOB_ID = 'scheduled-content';

    public const int BATCH_SIZE = 10;

    private const int CONTINUATION_DELAY_SECONDS = 1;

    public function __construct(
        private ContentPublicationScheduler $scheduler,
        private QueuePublisher              $queuePublisher,
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
        return 0.1;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($id !== self::JOB_ID || $code !== self::CODE || $payload !== []) {
            throw new \InvalidArgumentException('Invalid scheduled-content publication job.');
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $published = $this->scheduler->publishDueBatch(time(), self::BATCH_SIZE, $budget);
        if ($published === self::BATCH_SIZE) {
            $budget->checkpoint(0.02);
            $this->queuePublisher->publish(
                self::JOB_ID,
                self::CODE,
                availableAt: time() + self::CONTINUATION_DELAY_SECONDS,
            );
        }
    }
}
