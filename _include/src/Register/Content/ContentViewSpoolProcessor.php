<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Psr\Log\LoggerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO;

/** Commits one aggregated spool segment with a transactional exactly-once receipt. */
final readonly class ContentViewSpoolProcessor
{
    public function __construct(
        private PDO                   $pdo,
        private DbLayer               $dbLayer,
        private ContentViewRepository $repository,
        private ContentViewSpool      $spool,
        private LoggerInterface       $logger,
    ) {
    }

    public function canProcess(): bool
    {
        return !$this->pdo->inTransaction();
    }

    /** @return int Number of valid increments applied by this process. */
    public function process(string $segment): int
    {
        if (!$this->canProcess()) {
            throw new \LogicException('A content-view spool segment requires its own transaction.');
        }

        $lock = $this->spool->acquireSegment($segment);
        if ($lock === null) {
            return 0;
        }

        try {
            $batch = $this->spool->readSegment($segment);
            if ($batch['invalid'] > 0) {
                $this->logger->warning('Invalid records were skipped in a content-view spool segment.', [
                    'invalid_records' => $batch['invalid'],
                    'segment'         => basename($segment),
                ]);
            }

            $receiptId = $this->spool->segmentId($segment);
            $appliedCount = 0;
            $this->pdo->beginTransaction();
            try {
                $applied = $this->dbLayer
                    ->insert(ContentViewSpoolReceiptSchema::TABLE_NAME)
                    ->setValue('receipt_id', ':receipt_id')->setParameter('receipt_id', $receiptId)
                    ->setValue('created_at', ':created_at')->setParameter('created_at', time())
                    ->onConflictDoNothing('receipt_id')
                    ->execute()
                    ->affectedRows() > 0;
                if ($applied) {
                    $increments = $this->existingIncrements($batch['increments']);
                    $this->repository->recordBatch(...$increments);
                    $appliedCount = \count($increments);
                }

                $this->pdo->commit();
            } catch (\Throwable $throwable) {
                $this->rollbackIfActive();

                throw $throwable;
            }

            // A crash after COMMIT leaves both the segment and its receipt. The next pass sees the
            // receipt, skips the counters and safely completes these two cleanup operations.
            $this->spool->removeSegment($segment);
            try {
                $this->dbLayer
                    ->delete(ContentViewSpoolReceiptSchema::TABLE_NAME)
                    ->where('receipt_id = :receipt_id')->setParameter('receipt_id', $receiptId)
                    ->execute();
            } catch (\Throwable $throwable) {
                $this->logger->warning('A processed content-view spool receipt could not be removed.', [
                    'receipt_id' => $receiptId,
                    'exception'  => $throwable,
                ]);
            }

            return $appliedCount;
        } finally {
            $this->spool->releaseSegment($segment, $lock);
        }
    }

    /**
     * A content item can be deleted while its last view is waiting in the spool. Filtering in
     * the batch transaction keeps that harmless race from poisoning the segment with an FK error.
     *
     * @param list<ContentViewIncrement> $increments
     * @return list<ContentViewIncrement>
     */
    private function existingIncrements(array $increments): array
    {
        $ids = [];
        foreach ($increments as $increment) {
            $ids[$increment->contentId->value] = $increment->contentId->value;
        }

        $existing = [];
        foreach (array_chunk(array_values($ids), 250) as $chunkIndex => $chunk) {
            $parameters = [];
            $placeholders = [];
            foreach ($chunk as $rowIndex => $id) {
                $parameter = 'content_view_' . $chunkIndex . '_' . $rowIndex;
                $parameters[$parameter] = $id;
                $placeholders[] = ':' . $parameter;
            }

            foreach ($this->dbLayer
                ->select('id, content_type')
                ->from(ContentSchema::TABLE_NAME)
                ->where('id IN (' . implode(', ', $placeholders) . ')')
                ->execute($parameters)
                ->fetchAssocAll() as $row
            ) {
                $existing[(string)$row['content_type'] . ':' . (int)$row['id']] = true;
            }
        }

        return array_values(array_filter(
            $increments,
            static fn(ContentViewIncrement $increment): bool => isset($existing[(string)$increment->contentId]),
        ));
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
