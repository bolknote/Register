<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Core\Config\IntProxy;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\TocEntryWithMetadata;
use Register\Module\Search\Layout\ContentItem;
use Register\Module\Search\Layout\LayoutMatcherFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Core\Pdo\DbLayerException;

readonly class RecommendationProvider implements QueueHandlerInterface
{
    private const int COLD_CACHE_PLACEHOLDER_SECONDS = 3600;

    public const string INVALIDATED_AT = 'invalidatedAt';

    public const string RECOMMENDATIONS_QUEUE = 'recommendations';

    public const string CACHE_KEY_PREFIX = 'recommendations_';

    public function __construct(
        private RecommendationFinder $recommendationFinder,
        private LayoutMatcherFactory $layoutMatcherFactory,
        private CacheInterface       $cache,
        private QueuePublisher       $queuePublisher,
        private IntProxy             $recommendationsLimit,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::RECOMMENDATIONS_QUEUE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.5;
    }

    /**
     * @throws InvalidArgumentException
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getRecommendations(string $page, ExternalId $externalId, bool $buildColdCache = true): array
    {
        if ($this->recommendationsLimit->get() <= 0) {
            return [[], [], []];
        }

        $cacheKey = $this->getCacheKey($externalId);
        $cached = $this->cache->get($cacheKey, fn(ItemInterface $item): array => $buildColdCache
            ? $this->getValueForCache($externalId)
            : $this->getColdCachePlaceholder($item));

        if (($cached[2] ?? false) === true && $buildColdCache) {
            $this->cache->delete($cacheKey);
            $cached = $this->cache->get($cacheKey, fn(ItemInterface $_item): array => $this->getValueForCache($externalId));
        }

        [$recommendations, $generatedAt] = $cached;

        $cacheInvalidatedAt = $this->cache->get(self::INVALIDATED_AT, fn(ItemInterface $_item): int => time());
        if ($buildColdCache && $cacheInvalidatedAt > $generatedAt + 1) {
            // +1 to protect from rebuilding
            $this->queuePublisher->publish($externalId->toString(), self::RECOMMENDATIONS_QUEUE);
        }

        if ($recommendations === []) {
            return [[], [], []];
        }

        return array_merge($this->processRecommendations($page, $recommendations), [$recommendations]);
    }

    /**
     * {@inheritdoc}
     * @throws InvalidArgumentException
     * @param array<mixed> $payload
     */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        unset($payload);
        $budget->checkpoint($this->minimumExecutionTime());

        if ($code !== self::RECOMMENDATIONS_QUEUE) {
            throw new \LogicException(\sprintf('Unsupported recommendations queue code "%s".', $code));
        }

        $externalId = ExternalId::fromString($id);
        $cacheKey   = $this->getCacheKey($externalId);

        $this->cache->delete($cacheKey);
        $budget->checkpoint(0.5);
        $this->cache->get($cacheKey, fn(ItemInterface $_item): array => $this->getValueForCache($externalId));
    }

    /**
     * @return array<int, mixed>
     * @param array<mixed> $recommendations
     */
    private function processRecommendations(string $page, array $recommendations): array
    {
        $contentItems = [];
        foreach ($recommendations as $recommendation) {
            $tocWithMetadata = $recommendation['tocWithMetadata'] ?? null;
            if (!$tocWithMetadata instanceof TocEntryWithMetadata) {
                throw new \LogicException('tocWithMetadata key must contain TocEntryWithMetadata');
            }

            $tocEntry    = $tocWithMetadata->getTocEntry();
            $contentItem = new ContentItem(
                $tocEntry->getTitle(),
                $tocEntry->getUrl(),
                $tocEntry->getDate()
            );

            $contentItem->attachTextSnippet($recommendation['snippet'] ?? '');
            $contentItem->attachTextSnippet($recommendation['snippet2'] ?? '');

            foreach ($tocWithMetadata->getImgCollection()->toArray() as $image) {
                if ($image->hasNumericDimensions()) {
                    $contentItem->addImage($image->getSrc(), $image->getWidth(), $image->getHeight());
                }
            }

            $contentItems[] = $contentItem;
        }

        $layoutMatcher  = $this->layoutMatcherFactory->createLayoutMatcher();
        [$config, $log] = $layoutMatcher->match($page, ...$contentItems);

        return [$config, $log];
    }

    private function getCacheKey(ExternalId $externalId): string
    {
        return self::CACHE_KEY_PREFIX . hash('sha256', $externalId->toString());
    }

    /** @return array{array<mixed>, int, true} */
    private function getColdCachePlaceholder(ItemInterface $item): array
    {
        // A crawler can discover thousands of old pages at once. Computing a
        // cold recommendation set or even inserting one queue row per page
        // amplifies that crawl into an expensive SQL burst. A verified browser
        // replaces this local placeholder with a fully populated cache entry.
        $item->expiresAfter(self::COLD_CACHE_PLACEHOLDER_SECONDS);

        return [[], time(), true];
    }

    /**
     * @return array<mixed>
     */
    private function getValueForCache(ExternalId $externalId): array
    {
        try {
            $similar = $this->recommendationFinder->getSimilar($externalId, true, null, 4, 9);
            if ($similar === []) {
                $similar = $this->recommendationFinder->getSimilar($externalId, true, null, 2, 9);
            }

            return [
                $similar,
                time()
            ];
        } catch (\Throwable $exception) {
            if (!$exception instanceof \LogicException) {
                throw $exception;
            }

            return [[], time()];
        }
    }
}
