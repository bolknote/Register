<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Rose\Indexer;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\RecommendationProvider;

/**
 * Reconciles the search index synchronously with all built-in content providers.
 *
 * Normal content changes use the queue. This service is for installation, schema adoption, and
 * command-line bootstrap operations. HTTP repair uses durable queue jobs instead.
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
        try {
            $staleDocuments = [];
            foreach ($this->storage->getTocByTitlePrefix('') as $indexedDocument) {
                $externalId                  = $indexedDocument->getExternalId();
                $staleDocuments[$externalId->toString()] = $externalId;
            }
        } catch (EmptyIndexException) {
            $this->storage->erase();
            $staleDocuments = [];
        }

        $documentCount = 0;
        foreach ($this->providers as $provider) {
            foreach ($provider->getIndexables() as $indexable) {
                $this->indexer->index($indexable);
                unset($staleDocuments[$indexable->getExternalId()->toString()]);
                ++$documentCount;
            }
        }

        foreach ($staleDocuments as $externalId) {
            $this->indexer->removeById($externalId->getId(), $externalId->getInstanceId());
        }

        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);

        return $documentCount;
    }
}
