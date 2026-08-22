<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use Psr\Log\LoggerInterface;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use s2_extensions\activitypub\Domain\ContentProjectionMode;
use s2_extensions\activitypub\Infrastructure\ContentBackfillJob;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\ContentBackfillRepository;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;

/** Projects exactly one historical content item per shutdown queue generation. */
final readonly class ContentBackfillQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'register_activitypub_backfill';

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private ContentBackfillRepository   $backfillRepository,
        private ContentProjectionService    $projectionService,
        private PortableDatabaseTransaction $transaction,
        private QueuePublisher               $queuePublisher,
        private LoggerInterface              $logger,
        ?\Closure                            $clock = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
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
        return 0.08;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $jobId = $payload['job_id'] ?? null;
        if ($code !== self::CODE
            || !\is_string($jobId)
            || $id !== $jobId
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $jobId) !== 1
            || array_diff_key($payload, ['job_id' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid ActivityPub content backfill job.');
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $job = $this->backfillRepository->find($jobId);
        if (!$job instanceof ContentBackfillJob) {
            throw new \RuntimeException('The queued ActivityPub backfill manifest is missing.');
        }

        $contentId = $this->backfillRepository->nextPending($jobId, $now);
        if (!$contentId instanceof \Register\Content\ContentId) {
            $this->backfillRepository->finishIfExhausted($jobId, $now);
            return;
        }

        try {
            $this->transaction->run(function () use ($jobId, $contentId, $now): void {
                $result = $this->projectionService->synchronize(
                    $contentId,
                    ContentProjectionMode::HISTORY_ONLY,
                    $now,
                );
                $this->backfillRepository->markProcessed($jobId, $contentId, $result->action, $now);
            });
        } catch (\Throwable $exception) {
            $this->transaction->run(function () use ($jobId, $contentId, $exception, $now): void {
                $this->backfillRepository->markFailed(
                    $jobId,
                    $contentId,
                    $exception->getMessage(),
                    $now,
                );
            });
            $this->logger->error('ActivityPub historical content projection failed.', [
                'job_id'     => $jobId,
                'content_id' => (string)$contentId,
                'exception'  => $exception,
            ]);
        }

        if (!$this->backfillRepository->finishIfExhausted($jobId, $now)) {
            $this->queuePublisher->publish(
                $jobId,
                self::CODE,
                ['job_id' => $jobId],
                $now + 1,
            );
        }
    }
}
