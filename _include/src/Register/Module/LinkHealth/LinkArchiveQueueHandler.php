<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Core\Config\BoolProxy;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;

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
            || (!$force && \in_array(
                $target->archiveStatus,
                [ArchiveStatus::AVAILABLE, ArchiveStatus::MISSING],
                true,
            ))
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
            $referenceTime = $target->lastSuccessAt
                ?? $this->repository->archiveReferenceTime($targetId)
                ?? $target->lastSeenAt;
            $result = $this->waybackClient->lookup(
                $target->url,
                $referenceTime,
            );
        } catch (\Throwable $throwable) {
            $this->requestThrottle->backOff(
                $now,
                $throwable instanceof WaybackRequestException && $throwable->statusCode === 429,
            );
            $this->repository->recordArchiveError($targetId, $now);
            throw $throwable;
        }

        $this->resultRecorder->recordArchiveLookup($token, $target, $result, $now, $this->autoRepair->get());
    }
}
