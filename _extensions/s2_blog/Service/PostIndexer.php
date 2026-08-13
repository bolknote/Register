<?php
/**
 * 1. Updates search index when a visible post has been changed.
 * 2. Provides blog posts data for bulk indexing.
 *
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace s2_extensions\s2_blog\Service;

use Psr\Cache\CacheItemPoolInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Rose\Entity\Indexable;
use S2\Rose\Indexer;
use s2_extensions\s2_blog\BlogUrlBuilder;
use s2_extensions\s2_search\Service\BulkIndexingProviderInterface;
use s2_extensions\s2_search\Service\RecommendationProvider;
use Psr\Cache\InvalidArgumentException;
use S2\Cms\Pdo\DbLayerException;

readonly class PostIndexer implements QueueHandlerInterface, BulkIndexingProviderInterface
{
    public function __construct(
        private DbLayer                 $dbLayer,
        private BlogUrlBuilder          $blogUrlBuilder,
        private ?Indexer                $indexer,
        private ?CacheItemPoolInterface $cache,
        private QueuePublisher          $queuePublisher,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws InvalidArgumentException
     * @param array<mixed> $payload
     */
    #[\Override]
    public function handle(string $id, string $code, array $payload): bool
    {
        if ($code !== 's2_search_BlogPost') {
            return false;
        }

        if (!$this->indexer instanceof \S2\Rose\Indexer) {
            return true;
        }

        $indexable = $this->getIndexable((int)$id);
        if ($indexable instanceof \S2\Rose\Entity\Indexable) {
            $this->indexer->index($indexable);
            $this->queuePublisher->publish($indexable->getExternalId()->toString(), RecommendationProvider::RECOMMENDATIONS_QUEUE);
        } else {
            $this->indexer->removeById('s2_blog_' . $id, null);
        }

        $this->invalidateRecommendationsCache();

        return true;
    }

    /**
     * @throws DbLayerException
     * @throws \Exception
     */
    #[\Override]
    public function getIndexables(): \Generator
    {
        $result = $this->dbLayer
            ->select('id, title, text, create_time, url')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->execute()
        ;

        while ($post = $result->fetchAssoc()) {
            $indexable = $this->getIndexableFromDbRow($post);
            yield $indexable;
        }
    }


    /**
     * @throws DbLayerException
     * @throws \Exception
     */
    private function getIndexable(int $id): ?Indexable
    {
        $result = $this->dbLayer
            ->select('id, title, text, create_time, url')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->andWhere('id = :id')
            ->setParameter('id', $id)
            ->execute()
        ;

        $post = $result->fetchAssoc();
        if ($post === false) {
            return null;
        }

        return $this->getIndexableFromDbRow($post);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function invalidateRecommendationsCache(): void
    {
        if ($this->cache instanceof \Psr\Cache\CacheItemPoolInterface) {
            $this->cache->deleteItem(RecommendationProvider::INVALIDATED_AT);
        }
    }

    /**
     * @throws \Exception
     * @param array<string, mixed> $post
     */
    private function getIndexableFromDbRow(array $post): Indexable
    {
        $dateTime  = null;
        $timestamp = (int)$post['create_time'];
        if ($timestamp > 0) {
            // Rose currently requires the mutable DateTime implementation.
            $dateTime = (new \DateTime())->setTimestamp($timestamp);
        }

        $indexable = new Indexable('s2_blog_' . $post['id'], $post['title'], $post['text']);
        $indexable
            ->setDate($dateTime)
            ->setUrl($this->blogUrlBuilder->postWithoutPrefix($post['url']))
        ;
        return $indexable;
    }
}
