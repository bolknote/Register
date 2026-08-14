<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;

final readonly class LinkRepairQueueHandler implements QueueHandlerInterface
{
    public function __construct(
        private LinkHealthRepository $repository,
        private LinkRepairService     $repairService,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [LinkQueue::REPAIR_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.25;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $targetId = $payload['target_id'] ?? null;
        if ($code !== LinkQueue::REPAIR_CODE
            || !\is_int($targetId)
            || $targetId < 1
            || $id !== LinkQueue::targetJobId($targetId)
            || array_keys($payload) !== ['target_id']
        ) {
            throw new \InvalidArgumentException('Invalid link-repair job.');
        }

        $target = $this->repository->findTarget($targetId);
        if (!$target instanceof LinkTargetState) {
            return;
        }

        if ($target->kind !== LinkKind::EXTERNAL
            || $target->healthStatus !== LinkHealthStatus::BROKEN
            || $target->archiveStatus !== ArchiveStatus::AVAILABLE
            || $target->archiveUrl === null
            || !$this->repository->hasUsages($targetId)
        ) {
            return;
        }

        foreach ($this->repository->repairUsages($targetId) as $usage) {
            $budget->checkpoint($this->minimumExecutionTime());
            $this->repairService->repair($usage, $targetId, $target->url, $target->archiveUrl);
        }
    }
}
