<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Core\Queue\QueuePublisher;

final readonly class LinkHealthResultRecorder
{
    public function __construct(
        private LinkHealthRepository  $repository,
        private LinkHealthTransaction $transaction,
        private QueuePublisher        $queuePublisher,
    ) {
    }

    public function recordProbe(
        string             $token,
        LinkTargetState    $target,
        LinkProbeResult    $probe,
        LinkHealthDecision $decision,
        int                $now,
    ): void {
        $this->transaction->run(function () use ($token, $target, $probe, $decision, $now): void {
            if ($this->repository->probeWasRecorded($token)
                || !$this->repository->recordProbe($token, $target, $probe, $decision, $now)
            ) {
                return;
            }

            // AVAILABLE and MISSING are both completed Wayback lookups. In particular, historical
            // imports can seed either result before the current URL receives its confirming probe;
            // do not repeat that already completed network work when the URL becomes broken.
            if ($decision->lookupArchive && \in_array(
                $target->archiveStatus,
                [ArchiveStatus::UNCHECKED, ArchiveStatus::ERROR],
                true,
            )) {
                $this->queuePublisher->publish(
                    LinkQueue::targetJobId($target->id),
                    LinkQueue::ARCHIVE_CODE,
                    LinkQueue::archivePayload($target->id),
                );
            }
        });
    }

    public function recordArchiveLookup(
        string              $token,
        LinkTargetState     $target,
        ArchiveLookupResult $result,
        int                 $now,
        bool                $autoRepair,
    ): void {
        $this->transaction->run(function () use ($token, $target, $result, $now, $autoRepair): void {
            if ($this->repository->archiveLookupWasRecorded($target->id, $token)
                || !$this->repository->recordArchiveLookup($token, $target, $result, $now)
            ) {
                return;
            }

            if ($result->status === ArchiveStatus::AVAILABLE && $autoRepair) {
                $this->queuePublisher->publish(
                    LinkQueue::targetJobId($target->id),
                    LinkQueue::REPAIR_CODE,
                    ['target_id' => $target->id],
                );
            }
        });
    }
}
