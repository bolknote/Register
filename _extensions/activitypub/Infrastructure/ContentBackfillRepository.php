<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ContentBackfillMode;
use Register\Extension\activitypub\Domain\ContentBackfillState;
use Register\Extension\activitypub\Domain\ContentProjectionAction;

/** Durable audit and cursor for bounded, delivery-free historical projection. */
final readonly class ContentBackfillRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @param list<ContentId> $contentIds */
    public function create(
        string              $jobId,
        ContentBackfillMode $mode,
        array               $contentIds,
        int                 $requestedBy,
        int                 $now,
    ): ContentBackfillJob {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $jobId) !== 1
            || $requestedBy < 1
            || $now < 1
            || \count($contentIds) > 500
        ) {
            throw new \InvalidArgumentException('The ActivityPub backfill job is invalid.');
        }

        $normalized = [];
        foreach ($contentIds as $contentId) {
            $normalized[(string)$contentId] = $contentId;
        }

        $contentIds = array_values($normalized);
        $state = $contentIds === [] ? ContentBackfillState::COMPLETED : ContentBackfillState::PENDING;
        $this->dbLayer->insert(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->values([
                'id'              => ':id',
                'selection_mode'  => ':selection_mode',
                'state'           => ':state',
                'requested_by'    => ':requested_by',
                'total_count'     => ':total_count',
                'processed_count' => '0',
                'projected_count' => '0',
                'skipped_count'   => '0',
                'failed_count'    => '0',
                'created_at'      => ':created_at',
                'started_at'      => 'NULL',
                'completed_at'    => ':completed_at',
                'updated_at'      => ':updated_at',
            ])
            ->execute([
                'id'             => $jobId,
                'selection_mode' => $mode->value,
                'state'          => $state->value,
                'requested_by'   => $requestedBy,
                'total_count'    => \count($contentIds),
                'created_at'     => $now,
                'completed_at'   => $contentIds === [] ? $now : null,
                'updated_at'     => $now,
            ])
        ;
        foreach ($contentIds as $sequence => $contentId) {
            $this->dbLayer->insert(ActivityPubSchema::BACKFILL_ITEM_TABLE)
                ->values([
                    'job_id'         => ':job_id',
                    'sequence_number' => ':sequence_number',
                    'local_type'      => ':local_type',
                    'local_id'        => ':local_id',
                    'state'           => ':state',
                    'result_action'   => ':result_action',
                    'last_error'      => ':last_error',
                    'created_at'      => ':created_at',
                    'processed_at'    => 'NULL',
                ])
                ->execute([
                    'job_id'          => $jobId,
                    'sequence_number' => $sequence + 1,
                    'local_type'      => $contentId->type->value,
                    'local_id'        => $contentId->value,
                    'state'           => 'pending',
                    'result_action'   => '',
                    'last_error'      => '',
                    'created_at'      => $now,
                ])
            ;
        }

        return $this->find($jobId)
            ?? throw new \RuntimeException('The ActivityPub backfill job was not stored.');
    }

    public function find(string $jobId): ?ContentBackfillJob
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $jobId) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->where('id = :id')->setParameter('id', $jobId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<ContentBackfillJob> */
    public function recent(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The ActivityPub backfill history limit is invalid.');
        }

        $rows = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->orderBy('created_at DESC', 'id DESC')
            ->limit($limit)
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function nextPending(string $jobId, int $now): ?ContentId
    {
        $job = $this->find($jobId);
        if (!$job instanceof ContentBackfillJob
            || !\in_array($job->state, [ContentBackfillState::PENDING, ContentBackfillState::RUNNING], true)
        ) {
            return null;
        }

        $this->dbLayer->update(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->set('state', ':running')->setParameter('running', ContentBackfillState::RUNNING->value)
            ->set('started_at', 'COALESCE(started_at, :started_at)')->setParameter('started_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $jobId)
            ->andWhere('state IN (:pending, :already_running)')
            ->setParameter('pending', ContentBackfillState::PENDING->value)
            ->setParameter('already_running', ContentBackfillState::RUNNING->value)
            ->execute()
        ;
        $row = $this->dbLayer->select('local_type', 'local_id')
            ->from(ActivityPubSchema::BACKFILL_ITEM_TABLE)
            ->where('job_id = :job_id')->setParameter('job_id', $jobId)
            ->andWhere('state = :state')->setParameter('state', 'pending')
            ->orderBy('sequence_number')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        try {
            return new ContentId(ContentType::from((string)$row['local_type']), (int)$row['local_id']);
        } catch (\ValueError | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub backfill item is invalid.', 0, $exception);
        }
    }

    public function markProcessed(
        string                  $jobId,
        ContentId               $contentId,
        ContentProjectionAction $action,
        int                     $now,
    ): void {
        $projected = \in_array($action, [
            ContentProjectionAction::CREATED,
            ContentProjectionAction::UPDATED,
            ContentProjectionAction::REPLACED,
        ], true);
        $updated = $this->dbLayer->update(ActivityPubSchema::BACKFILL_ITEM_TABLE)
            ->set('state', ':state')->setParameter('state', 'completed')
            ->set('result_action', ':result_action')->setParameter('result_action', $action->value)
            ->set('last_error', ':last_error')->setParameter('last_error', '')
            ->set('processed_at', ':processed_at')->setParameter('processed_at', $now)
            ->where('job_id = :job_id')->setParameter('job_id', $jobId)
            ->andWhere('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->andWhere('state = :pending')->setParameter('pending', 'pending')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            throw new \RuntimeException('The ActivityPub backfill item changed while it was being projected.');
        }

        $jobUpdate = $this->dbLayer->update(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->set('processed_count', 'processed_count + 1')
            ->set($projected ? 'projected_count' : 'skipped_count',
                ($projected ? 'projected_count' : 'skipped_count') . ' + 1')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $jobId)
            ->andWhere('state = :state')->setParameter('state', ContentBackfillState::RUNNING->value)
            ->execute()
            ->affectedRows()
        ;
        if ($jobUpdate !== 1) {
            throw new \RuntimeException('The ActivityPub backfill job counter could not be advanced.');
        }

    }

    public function markFailed(string $jobId, ContentId $contentId, string $error, int $now): void
    {
        $error = mb_substr(trim($error), 0, 2_000);
        $updated = $this->dbLayer->update(ActivityPubSchema::BACKFILL_ITEM_TABLE)
            ->set('state', ':state')->setParameter('state', 'failed')
            ->set('result_action', ':result_action')->setParameter('result_action', '')
            ->set('last_error', ':last_error')->setParameter('last_error', $error)
            ->set('processed_at', ':processed_at')->setParameter('processed_at', $now)
            ->where('job_id = :job_id')->setParameter('job_id', $jobId)
            ->andWhere('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->andWhere('state = :pending')->setParameter('pending', 'pending')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            throw new \RuntimeException('The failed ActivityPub backfill item could not be recorded.');
        }

        $jobUpdate = $this->dbLayer->update(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->set('processed_count', 'processed_count + 1')
            ->set('failed_count', 'failed_count + 1')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $jobId)
            ->andWhere('state = :state')->setParameter('state', ContentBackfillState::RUNNING->value)
            ->execute()
            ->affectedRows()
        ;
        if ($jobUpdate !== 1) {
            throw new \RuntimeException('The ActivityPub backfill failure counter could not be advanced.');
        }

    }

    public function finishIfExhausted(string $jobId, int $now): bool
    {
        $pending = (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::BACKFILL_ITEM_TABLE)
            ->where('job_id = :job_id')->setParameter('job_id', $jobId)
            ->andWhere('state = :state')->setParameter('state', 'pending')
            ->execute()
            ->result()
        ;
        if ($pending > 0) {
            return false;
        }

        $job = $this->find($jobId);
        if (!$job instanceof ContentBackfillJob) {
            return false;
        }

        if ($job->state !== ContentBackfillState::RUNNING) {
            return \in_array($job->state, [
                ContentBackfillState::COMPLETED,
                ContentBackfillState::COMPLETED_WITH_ERRORS,
            ], true);
        }

        $state = $job->failedCount > 0
            ? ContentBackfillState::COMPLETED_WITH_ERRORS
            : ContentBackfillState::COMPLETED;

        return $this->dbLayer->update(ActivityPubSchema::BACKFILL_JOB_TABLE)
            ->set('state', ':completed')->setParameter('completed', $state->value)
            ->set('completed_at', ':completed_at')->setParameter('completed_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $jobId)
            ->andWhere('state = :running')->setParameter('running', ContentBackfillState::RUNNING->value)
            ->execute()
            ->affectedRows() === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ContentBackfillJob
    {
        try {
            return new ContentBackfillJob(
                (string)$row['id'],
                ContentBackfillMode::from((string)$row['selection_mode']),
                ContentBackfillState::from((string)$row['state']),
                (int)$row['requested_by'],
                (int)$row['total_count'],
                (int)$row['processed_count'],
                (int)$row['projected_count'],
                (int)$row['skipped_count'],
                (int)$row['failed_count'],
                (int)$row['created_at'],
                $row['started_at'] === null ? null : (int)$row['started_at'],
                $row['completed_at'] === null ? null : (int)$row['completed_at'],
                (int)$row['updated_at'],
            );
        } catch (\ValueError $exception) {
            throw new \RuntimeException('A stored ActivityPub backfill job is invalid.', 0, $exception);
        }
    }
}
