<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Search;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use S2\Rose\Indexer;
use S2\Rose\Storage\Database\PdoStorage;
use s2_extensions\s2_search\Service\BulkIndexingProviderInterface;
use s2_extensions\s2_search\Service\RecommendationProvider;

/**
 * Builds a usable search index synchronously from all built-in content providers.
 *
 * Normal content changes use the queue. This service is for installation, schema adoption, and
 * explicit repair operations where returning with an empty or partial index would be misleading.
 */
final readonly class SearchIndexRebuilder
{
    /** @var list<BulkIndexingProviderInterface> */
    private array $providers;

    public function __construct(
        private PdoStorage            $storage,
        private Indexer               $indexer,
        private CacheItemPoolInterface $recommendationsCache,
        BulkIndexingProviderInterface ...$providers,
    ) {
        $this->providers = array_values($providers);
    }

    /**
     * @throws InvalidArgumentException
     * @return int Number of indexed documents.
     */
    public function rebuild(): int
    {
        $this->storage->erase();

        $documentCount = 0;
        foreach ($this->providers as $provider) {
            foreach ($provider->getIndexables() as $indexable) {
                $this->indexer->index($indexable);
                ++$documentCount;
            }
        }

        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);

        return $documentCount;
    }
}
