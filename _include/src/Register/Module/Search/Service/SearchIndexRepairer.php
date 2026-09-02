<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Content\ContentRepository;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Indexer;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\Exception\EmptyIndexException;

/**
 * Reconciles the complete index through a durable, resumable queue cursor.
 *
 * The live index is never erased before replacement documents are ready. A transient database
 * lock therefore delays work instead of leaving search empty or permanently half-built. Each
 * queue slice indexes as many documents as fit its execution budget and persists the next offset
 * before returning.
 */
final readonly class SearchIndexRepairer implements QueueHandlerInterface
{
    public const string JOB_ID = 'all';

    public const string REPAIR_QUEUE_CODE = 'register_search_repair';

    public const string REMOVE_QUEUE_CODE = 'register_search_remove';

    private const int BATCH_SIZE = 50;

    /** Leaves enough time in an HTTP shutdown slice to persist the continuation cursor. */
    private const float INDEX_TIME_RESERVE_SECONDS = 0.75;

    public function __construct(
        private ContentRepository      $contentRepository,
        private PdoStorage             $pdoStorage,
        private Indexer                $indexer,
        private SearchDocumentFactory  $documentFactory,
        private CacheItemPoolInterface $recommendationsCache,
        private QueuePublisher         $queuePublisher,
    ) {
    }

    /** Restarts a missing or failed repair plan. Calling this for an active plan is harmless. */
    public function schedule(?int $availableAt = null): void
    {
        $this->queuePublisher->publish(
            self::JOB_ID,
            self::REPAIR_QUEUE_CODE,
            ['offset' => 0],
            $availableAt,
        );
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::REPAIR_QUEUE_CODE, self::REMOVE_QUEUE_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.25;
    }

    /**
     * @param array<mixed> $payload
     * @throws InvalidArgumentException
     */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        match ($code) {
            self::REPAIR_QUEUE_CODE => $this->planRepair($id, $payload, $budget),
            self::REMOVE_QUEUE_CODE => $this->removeStaleDocument($id, $payload, $budget),
            default => throw new \LogicException(\sprintf('Unsupported search repair queue code "%s".', $code)),
        };
    }

    /** @param array<mixed> $payload */
    private function planRepair(string $id, array $payload, QueueExecutionBudget $budget): void
    {
        if ($id !== self::JOB_ID) {
            throw new \InvalidArgumentException('A search repair job has an invalid identifier.');
        }

        if (array_keys($payload) !== ['offset'] || !\is_int($payload['offset']) || $payload['offset'] < 0) {
            throw new \InvalidArgumentException('A search repair job must contain a non-negative integer offset.');
        }

        $offset = $payload['offset'];
        if ($offset === 0) {
            $this->ensureStorageExists($budget);
        }

        $position = 0;
        $indexed  = 0;
        $hasMore  = false;
        foreach ($this->contentRepository->published() as $content) {
            if ($position++ < $offset) {
                continue;
            }

            if ($indexed >= self::BATCH_SIZE
                || !$budget->canStart(self::INDEX_TIME_RESERVE_SECONDS)
            ) {
                $hasMore = true;
                break;
            }

            $this->indexer->index($this->documentFactory->create($content));
            ++$indexed;
        }

        if ($hasMore) {
            // Persisting progress is mandatory even when indexing consumed most of the soft
            // budget. Throwing here would retry the same prefix forever on a slow shared host.
            $this->queuePublisher->publish(
                self::JOB_ID,
                self::REPAIR_QUEUE_CODE,
                ['offset' => $offset + $indexed],
                time() + 1,
            );
            $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);
            return;
        }

        $this->scheduleStaleDocumentRemoval($budget);
        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);
    }

    /** @param array<mixed> $payload */
    private function removeStaleDocument(string $id, array $payload, QueueExecutionBudget $budget): void
    {
        if ($payload !== []) {
            throw new \InvalidArgumentException('A stale search document removal job must not contain a payload.');
        }

        $externalId = ExternalId::fromString($id);
        $budget->checkpoint(0.15);
        $this->indexer->removeById($externalId->getId(), $externalId->getInstanceId());
        $budget->checkpoint(0.02);
        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);
    }

    private function ensureStorageExists(QueueExecutionBudget $budget): void
    {
        $budget->checkpoint(0.1);
        try {
            $this->pdoStorage->getTocSize(null);
        } catch (EmptyIndexException) {
            $budget->checkpoint(0.2);
            $this->pdoStorage->erase();
        }
    }

    private function scheduleStaleDocumentRemoval(QueueExecutionBudget $budget): void
    {
        $expected = [];
        foreach ($this->contentRepository->published() as $content) {
            $budget->checkpoint(0.005);
            $expected[$this->queueExternalId((string)$content->id)] = true;
        }

        $budget->checkpoint(0.05);
        foreach ($this->pdoStorage->getTocByTitlePrefix('') as $indexedDocument) {
            $budget->checkpoint(0.02);
            $externalId = $indexedDocument->getExternalId()->toString();
            if (!isset($expected[$externalId])) {
                $this->queuePublisher->publish($externalId, self::REMOVE_QUEUE_CODE);
            }
        }
    }

    private function queueExternalId(string $id): string
    {
        return (new ExternalId($id))->toString();
    }
}
