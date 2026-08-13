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
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Rose\Entity\Indexable;
use S2\Rose\Indexer;

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

    /**
     * @param array<mixed> $payload
     * @throws InvalidArgumentException
     */
    #[\Override]
    public function handle(string $id, string $code, array $payload): bool
    {
        if ($code !== self::QUEUE_CODE) {
            return false;
        }

        if ($payload !== []) {
            throw new \InvalidArgumentException('A content indexing job must not contain a payload.');
        }

        $contentId = ContentId::fromString($id);

        $content = $this->contentRepository->find($contentId);
        if ($content instanceof ContentItem) {
            $indexable = $this->documentFactory->create($content);
            $this->indexer->index($indexable);
            $this->queuePublisher->publish(
                $indexable->getExternalId()->toString(),
                RecommendationProvider::RECOMMENDATIONS_QUEUE,
            );
        } else {
            $this->indexer->removeById(SearchDocumentFactory::externalId($contentId), null);
        }

        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);

        return true;
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
