<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Domain\ContentBackfillMode;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\ContentBackfillJob;
use Register\Extension\activitypub\Infrastructure\ContentBackfillRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;

/** Creates a finite backfill manifest and its first shutdown generation atomically. */
final readonly class ContentBackfillStarter
{
    public function __construct(
        private ContentRepository            $contentRepository,
        private FederationStateRepository    $stateRepository,
        private ContentBackfillRepository    $backfillRepository,
        private PublicIdGenerator             $publicIdGenerator,
        private QueuePublisher                $queuePublisher,
        private PortableDatabaseTransaction  $transaction,
    ) {
    }

    public function latestPosts(int $limit, int $requestedBy, ?int $now = null): ContentBackfillJob
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('The ActivityPub latest-post backfill limit must be between 1 and 500.');
        }

        $contentIds = [];
        foreach ($this->contentRepository->recent(ContentType::POST, $limit) as $content) {
            $contentIds[] = $content->id;
        }

        return $this->start(ContentBackfillMode::LATEST, $contentIds, $requestedBy, $now ?? time());
    }

    /** @param list<ContentId> $contentIds */
    public function selected(array $contentIds, int $requestedBy, ?int $now = null): ContentBackfillJob
    {
        if ($contentIds === [] || \count($contentIds) > 500) {
            throw new \InvalidArgumentException('Select between 1 and 500 published items for ActivityPub backfill.');
        }

        $normalized = [];
        foreach ($contentIds as $contentId) {
            $content = $this->contentRepository->find($contentId);
            if (!$content instanceof ContentItem) {
                throw new \DomainException('Every selected ActivityPub backfill item must still be published.');
            }

            $normalized[(string)$contentId] = $contentId;
        }

        return $this->start(
            ContentBackfillMode::SELECTED,
            array_values($normalized),
            $requestedBy,
            $now ?? time(),
        );
    }

    /** @param list<ContentId> $contentIds */
    private function start(
        ContentBackfillMode $mode,
        array               $contentIds,
        int                 $requestedBy,
        int                 $now,
    ): ContentBackfillJob {
        if ($requestedBy < 1 || $now < 1) {
            throw new \InvalidArgumentException('The ActivityPub backfill request context is invalid.');
        }

        $lifecycle = $this->stateRepository->lifecycleState();
        if (!\in_array($lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)) {
            throw new \DomainException('Historical ActivityPub projection requires active or paused federation.');
        }

        $jobId = $this->publicIdGenerator->generate();

        return $this->transaction->run(function () use ($jobId, $mode, $contentIds, $requestedBy, $now): ContentBackfillJob {
            $job = $this->backfillRepository->create($jobId, $mode, $contentIds, $requestedBy, $now);
            if ($contentIds !== []) {
                $this->queuePublisher->publish(
                    $jobId,
                    ContentBackfillQueueHandler::CODE,
                    ['job_id' => $jobId],
                    $now + 1,
                );
            }

            return $job;
        });
    }
}
