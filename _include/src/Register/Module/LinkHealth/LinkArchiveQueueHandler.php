<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\Config\BoolProxy;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;

final readonly class LinkArchiveQueueHandler implements QueueHandlerInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private LinkHealthRepository   $repository,
        private WaybackClientInterface $waybackClient,
        private WaybackRequestThrottle $requestThrottle,
        private LinkHealthResultRecorder $resultRecorder,
        private QueuePublisher         $queuePublisher,
        private BoolProxy              $autoRepair,
        ?\Closure                      $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [LinkQueue::ARCHIVE_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 4.25;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $targetId = $payload['target_id'] ?? null;
        $token    = $payload['token'] ?? null;
        $force    = $payload['force'] ?? false;
        if ($code !== LinkQueue::ARCHIVE_CODE
            || !\is_int($targetId)
            || $targetId < 1
            || $id !== LinkQueue::targetJobId($targetId)
            || ($token !== null && !LinkQueue::isOperationToken($token))
            || !\is_bool($force)
            || array_diff_key($payload, ['target_id' => true, 'token' => true, 'force' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid link-archive job.');
        }

        $target = $this->repository->findTarget($targetId);
        if (!$target instanceof LinkTargetState) {
            return;
        }

        if ($target->kind !== LinkKind::EXTERNAL
            || $target->healthStatus !== LinkHealthStatus::BROKEN
            || !$this->repository->hasUsages($targetId)
            || (!$force && $target->archiveStatus === ArchiveStatus::AVAILABLE)
        ) {
            return;
        }

        // Jobs created before operation tokens were introduced are upgraded before any network I/O.
        if ($token === null) {
            $this->queuePublisher->publish(
                $id,
                $code,
                LinkQueue::archivePayload($targetId, $force),
                ($this->clock)() + 1,
            );
            return;
        }

        if ($this->repository->archiveLookupWasRecorded($targetId, $token)) {
            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now     = ($this->clock)();
        $retryAt = $this->requestThrottle->claim($now);
        if ($retryAt !== null) {
            $this->queuePublisher->publish($id, $code, $payload, $retryAt);
            return;
        }

        try {
            $result = $this->waybackClient->lookup(
                $target->url,
                $target->lastSuccessAt ?? $target->lastSeenAt,
            );
        } catch (\Throwable $throwable) {
            $this->repository->recordArchiveError($targetId, $now);
            throw $throwable;
        }

        $this->resultRecorder->recordArchiveLookup($token, $target, $result, $now, $this->autoRepair->get());
    }
}
