<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use S2\Cms\Config\IntProxy;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\TocEntryWithMetadata;
use Register\Module\Search\Layout\ContentItem;
use Register\Module\Search\Layout\LayoutMatcherFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Cache\InvalidArgumentException;
use S2\Cms\Pdo\DbLayerException;

readonly class RecommendationProvider implements QueueHandlerInterface
{
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

    /**
     * @throws InvalidArgumentException
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getRecommendations(string $page, ExternalId $externalId): array
    {
        if ($this->recommendationsLimit->get() <= 0) {
            return [[], [], []];
        }

        [$recommendations, $generatedAt] = $this->cache->get(
            $this->getCacheKey($externalId),
            fn(ItemInterface $_item): array => $this->getValueForCache($externalId)
        );

        $cacheInvalidatedAt = $this->cache->get(self::INVALIDATED_AT, fn(ItemInterface $_item): int => time());
        if ($cacheInvalidatedAt > $generatedAt + 1) {
            // +1 to protect from rebuilding
            $this->queuePublisher->publish($externalId->toString(), self::RECOMMENDATIONS_QUEUE);
        }

        return array_merge($this->processRecommendations($page, $recommendations), [$recommendations]);
    }

    /**
     * {@inheritdoc}
     * @throws InvalidArgumentException
     * @param array<mixed> $payload
     */
    #[\Override]
    public function handle(string $id, string $code, array $payload): void
    {
        unset($payload);

        if ($code !== self::RECOMMENDATIONS_QUEUE) {
            throw new \LogicException(\sprintf('Unsupported recommendations queue code "%s".', $code));
        }

        $externalId = ExternalId::fromString($id);
        $cacheKey   = $this->getCacheKey($externalId);

        $this->cache->delete($cacheKey);
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

            foreach ($tocWithMetadata->getImgCollection() as $image) {
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
