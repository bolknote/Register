<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Psr\Log\LoggerInterface;
use Register\Core\Queue\OpportunisticMaintenanceTaskInterface;
use Register\Core\Queue\QueueExecutionBudget;

/** Drains bounded event segments opportunistically and enforces analytics retention hourly. */
final readonly class AnalyticsMaintenanceTask implements OpportunisticMaintenanceTaskInterface
{
    private const int EVENT_RETENTION_SECONDS = 30 * 86400;

    private const int SESSION_RETENTION_SECONDS = 90 * 86400;

    private const int UNIQUE_RETENTION_SECONDS = 2 * 86400;

    private const int SEGMENTS_PER_SLICE = 4;

    public function __construct(
        private AnalyticsRepository $repository,
        private ?AnalyticsSpool     $spool = null,
        private ?AnalyticsIngestor  $ingestor = null,
        private ?LoggerInterface    $logger = null,
    ) {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The analytics maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.05);
        $this->repository->forgetVisitorFingerprintsBefore(date('Y-m-d', $now));
        if ($this->ingestor !== null) {
            $budget->checkpoint(0.05);
            $this->ingestor->purge(
                $now - self::EVENT_RETENTION_SECONDS,
                $now - self::SESSION_RETENTION_SECONDS,
                date('Y-m-d', $now - self::UNIQUE_RETENTION_SECONDS),
            );
        }
    }

    #[\Override]
    public function hasDueWork(int $now, QueueExecutionBudget $budget): bool
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The analytics maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.005);
        return $this->spool?->hasDueWork($now) ?? false;
    }

    #[\Override]
    public function runIfDue(int $now, QueueExecutionBudget $budget): bool
    {
        if (!$this->hasDueWork($now, $budget)) {
            return false;
        }

        if ($this->spool === null || $this->ingestor === null) {
            return false;
        }

        $budget->checkpoint(0.02);
        $this->spool->sealDue($now);
        $completed = false;
        foreach ($this->spool->sealedSegments(self::SEGMENTS_PER_SLICE) as $segment) {
            $budget->checkpoint(0.05);
            $batch = $this->spool->readSegment($segment);
            if ($batch['invalid'] > 0) {
                $this->logger?->warning('Invalid records were skipped in an analytics spool segment.', [
                    'invalid_records' => $batch['invalid'],
                    'segment'         => basename($segment),
                ]);
            }

            $this->ingestor->ingest($batch['events']);
            $this->spool->removeSegment($segment);
            $completed = true;
        }

        return $completed;
    }
}
