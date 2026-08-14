<?php

declare(strict_types = 1);

/**
 * Creates search index
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

namespace Register\Module\Search\Admin;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Exception\RuntimeException;
use S2\Rose\Indexer;
use S2\Rose\Storage\Database\PdoStorage;
use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\RecommendationProvider;
use Psr\Cache\InvalidArgumentException;

class IndexManager
{
    private const string FILE_PROCESS_STATE  = 's2_search_state.txt';

    private const string FILE_BUFFER_CONTENT = 's2_search_buffer.txt';

    private const string FILE_BUFFER_POINTER = 's2_search_pointer.txt';

    /**
     * @var BulkIndexingProviderInterface[]
     */
    private readonly array $bulkIndexingProviders;

    public function __construct(
        private readonly string                 $cacheDir,
        private readonly Indexer                $indexer,
        private readonly PdoStorage             $pdoStorage,
        private readonly CacheItemPoolInterface $recommendationsCache,
        private readonly LoggerInterface        $logger,
        BulkIndexingProviderInterface           ...$bulkIndexingProviders,
    ) {
        $this->bulkIndexingProviders = $bulkIndexingProviders;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function index(): string
    {
        $stateContent = is_file($this->cacheDir . self::FILE_PROCESS_STATE)
            ? file_get_contents($this->cacheDir . self::FILE_PROCESS_STATE)
            : false;
        $state = $stateContent === false || $stateContent === '' ? 'start' : $stateContent;

        if ($state === 'start') {
            // First stage: export all texts to buffer_name file

            $this->pdoStorage->erase();
            s2_call_without_warnings(fn(): bool => unlink($this->cacheDir . self::FILE_BUFFER_CONTENT));
            s2_call_without_warnings(fn(): bool => unlink($this->cacheDir . self::FILE_BUFFER_POINTER));
            s2_call_without_warnings(fn(): bool => unlink($this->cacheDir . self::FILE_PROCESS_STATE));

            $this->writeFile(self::FILE_BUFFER_POINTER, '0');

            $this->writeFile(self::FILE_BUFFER_CONTENT, '');
            foreach ($this->bulkIndexingProviders as $bulkIndexingProvider) {
                foreach ($bulkIndexingProvider->getIndexables() as $indexable) {
                    $this->writeFile(self::FILE_BUFFER_CONTENT, base64_encode(serialize($indexable)) . "\n", FILE_APPEND);
                }
            }

            $this->writeFile(self::FILE_PROCESS_STATE, 'step');

            clearstatcache();

            $this->invalidateRecommendationsCache();

            return 'go_20';
        }

        if ($state === 'step') {
            // Second stage: go through all exported data and add to index
            $start = microtime(true);

            $bufferFilePointer = file_get_contents($this->cacheDir . self::FILE_BUFFER_POINTER);
            if ($bufferFilePointer === false || !is_numeric($bufferFilePointer)) {
                throw new \RuntimeException('Search index buffer pointer is invalid.');
            }

            $bufferOffset = (int)$bufferFilePointer;

            $bufferFile = fopen($this->cacheDir . self::FILE_BUFFER_CONTENT, 'rb');
            if ($bufferFile === false) {
                throw new \RuntimeException('Unable to open the search index buffer.');
            }

            if (fseek($bufferFile, $bufferOffset) !== 0) {
                fclose($bufferFile);
                throw new \RuntimeException('Unable to seek in the search index buffer.');
            }

            do {
                $data = fgets($bufferFile);

                if ($data === false) {
                    // All indexed, no more data
                    fclose($bufferFile);
                    $this->writeFile(self::FILE_BUFFER_CONTENT, '');
                    $this->writeFile(self::FILE_BUFFER_POINTER, '');
                    $this->writeFile(self::FILE_PROCESS_STATE, '');

                    $this->invalidateRecommendationsCache();

                    return 'stop';
                }

                $bufferOffset += strlen($data);

                $serializedIndexable = base64_decode(trim($data), true);
                if ($serializedIndexable === false) {
                    throw new \RuntimeException('Search index buffer contains invalid Base64 data.');
                }

                $indexable = unserialize($serializedIndexable, [
                    'allowed_classes' => [Indexable::class, ExternalId::class, \DateTime::class],
                ]);
                if ($indexable instanceof Indexable) {
                    try {
                        $this->indexer->index($indexable);
                    } catch (RuntimeException $e) {
                        $this->writeFile(self::FILE_PROCESS_STATE, '');
                        $this->logger->error($e->getMessage(), ['exception' => $e]);
                    }
                }
            } while ($start + 2.0 > microtime(true));

            fclose($bufferFile);
            $this->writeFile(self::FILE_BUFFER_POINTER, (string)$bufferOffset);

            $this->invalidateRecommendationsCache();

            $bufferSize = filesize($this->cacheDir . self::FILE_BUFFER_CONTENT);
            if ($bufferSize === false || $bufferSize === 0) {
                return 'go_100';
            }

            return 'go_' . min(100, 20 + (int)(80.0 * (float)$bufferOffset / (float)$bufferSize));
        }

        $this->writeFile(self::FILE_PROCESS_STATE, '');

        return 'unknown state';
    }

    /**
     * @throws InvalidArgumentException
     */
    private function invalidateRecommendationsCache(): void
    {
        $this->recommendationsCache->deleteItem(RecommendationProvider::INVALIDATED_AT);
    }

    private function writeFile(string $relativePath, string $content, int $flags = 0): void
    {
        if (file_put_contents($this->cacheDir . $relativePath, $content, $flags) === false) {
            throw new \RuntimeException('Unable to write search index state file: ' . $relativePath);
        }
    }
}
