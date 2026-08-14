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
use S2\Cms\Queue\QueuePublisher;

final readonly class LinkCheckQueueHandler implements QueueHandlerInterface
{
    public function __construct(
        private LinkHealthRepository $repository,
        private LinkHealthPolicy     $policy,
        private LinkProbeInterface   $httpProbe,
        private QueuePublisher       $queuePublisher,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [LinkQueue::CHECK_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 8.0;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $targetId = $payload['target_id'] ?? null;
        $force    = $payload['force'] ?? false;
        if ($code !== LinkQueue::CHECK_CODE
            || !\is_int($targetId)
            || $targetId < 1
            || $id !== LinkQueue::targetJobId($targetId)
            || !\is_bool($force)
            || array_diff_key($payload, ['target_id' => true, 'force' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid link-check job.');
        }

        $target = $this->repository->findTarget($targetId);
        if (!$target instanceof LinkTargetState) {
            return;
        }

        if ($target->kind !== LinkKind::EXTERNAL
            || !$this->repository->hasUsages($targetId)
        ) {
            return;
        }

        if (!$force && \in_array($target->healthStatus, [
            LinkHealthStatus::BROKEN,
            LinkHealthStatus::BLOCKED,
            LinkHealthStatus::IGNORED,
        ], true)) {
            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now      = time();
        $probe    = $this->httpProbe->probe($target->url);
        $decision = $this->policy->decide($target, $probe, $now);
        $this->repository->recordProbe($target, $probe, $decision, $now);

        if ($decision->lookupArchive && $target->archiveStatus !== ArchiveStatus::AVAILABLE) {
            $budget->checkpoint(0.02);
            $this->queuePublisher->publishIfAbsent(
                LinkQueue::targetJobId($targetId),
                LinkQueue::ARCHIVE_CODE,
                ['target_id' => $targetId],
            );
        }
    }
}
