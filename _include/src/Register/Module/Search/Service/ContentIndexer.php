<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Rose\Entity\Indexable;
use Register\Rose\Indexer;

/** Indexes posts and pages through Register's common content contract. */
final readonly class ContentIndexer implements QueueHandlerInterface, BulkIndexingProviderInterface
{
    public const string QUEUE_CODE = 'register_content_index';

    public function __construct(
        private ContentRepository      $contentRepository,
        private SearchDocumentFactory  $documentFactory,
        private Indexer                $indexer,
        private CacheItemPoolInterface $recommendationsCache,
        private QueuePublisher         $queuePublisher,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::QUEUE_CODE];
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
        if ($code !== self::QUEUE_CODE) {
            throw new \LogicException(\sprintf('Unsupported content index queue code "%s".', $code));
        }

        if ($payload !== []) {
            throw new \InvalidArgumentException('A content indexing job must not contain a payload.');
        }

        $budget->checkpoint($this->minimumExecutionTime());

        $contentId = ContentId::fromString($id);

        $content = $this->contentRepository->find($contentId);
        if ($content instanceof ContentItem) {
            $indexable = $this->documentFactory->create($content);
            $budget->checkpoint(0.25);
            $this->indexer->index($indexable);
            $budget->checkpoint(0.05);
            $this->queuePublisher->publish(
                $indexable->getExternalId()->toString(),
                RecommendationProvider::RECOMMENDATIONS_QUEUE,
            );
        } else {
            $budget->checkpoint(0.1);
            $this->indexer->removeById(SearchDocumentFactory::externalId($contentId), null);
        }

        $budget->checkpoint(0.02);
        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);

    }

    /** @return \Generator<int, Indexable> */
    #[\Override]
    public function getIndexables(): \Generator
    {
        foreach ($this->contentRepository->published() as $content) {
            yield $this->documentFactory->create($content);
        }
    }

}
