<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Psr\Cache\CacheItemPoolInterface;
use Register\Comment\CommentSchema;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;

/** Writes daily aggregates and builds popular/hot post rankings without storing visitor data. */
final class ContentViewRepository implements StatefulServiceInterface
{
    private const string TOTAL_CACHE_PREFIX = 'content_view_total_v2_';

    private const string TOTAL_VERSION_PREFIX = 'content_view_total_version_v1_';

    /** @var array<string, true> */
    private array $pendingTotalInvalidations = [];

    public function __construct(
        private readonly DbLayer                 $dbLayer,
        private readonly ?CacheItemPoolInterface $totalCache = null,
        private readonly ?PDO                    $pdo = null,
    )
    {
    }

    public function record(ContentId $contentId, ?string $day = null): void
    {
        $day ??= gmdate('Y-m-d');
        $table = $this->dbLayer->getPrefix() . ContentViewSchema::TABLE_NAME;
        $parameters = [
            'content_type' => $contentId->type->value,
            'content_id'   => $contentId->value,
            'day'          => $day,
        ];
        $sql = match (true) {
            $this->dbLayer instanceof DbLayerPostgres => <<<SQL
                INSERT INTO $table (content_type, content_id, day, views)
                VALUES (:content_type, :content_id, :day, 1)
                ON CONFLICT (content_type, content_id, day) DO UPDATE SET
                    views = $table.views + 1
                SQL,
            $this->dbLayer instanceof DbLayerSqlite => <<<SQL
                INSERT INTO $table (content_type, content_id, day, views)
                VALUES (:content_type, :content_id, :day, 1)
                ON CONFLICT (content_type, content_id, day) DO UPDATE SET
                    views = views + 1
                SQL,
            default => <<<SQL
                INSERT INTO $table (content_type, content_id, day, views)
                VALUES (:content_type, :content_id, :day, 1)
                ON DUPLICATE KEY UPDATE views = views + 1
                SQL,
        };

        $this->dbLayer->query($sql, $parameters);
        if ($this->totalCache instanceof CacheItemPoolInterface) {
            $totalCache = $this->totalCache;
            $cacheKey = $this->totalCacheKey($contentId);
            $versionKey = $this->totalVersionKey($contentId);
            if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
                // Keep the old committed value visible to concurrent readers. This
                // request bypasses it below; COMMIT removes it for future requests.
                $this->pendingTotalInvalidations[$cacheKey] = true;
                $this->pdo->afterCommitOnce('content-view-total:' . (string)$contentId, function () use ($totalCache, $cacheKey, $versionKey): void {
                    $totalCache->deleteItem($cacheKey);
                    $totalCache->deleteItem($versionKey);
                    unset($this->pendingTotalInvalidations[$cacheKey]);
                });
                $this->pdo->afterRollbackOnce('content-view-total:' . (string)$contentId, function () use ($cacheKey): void {
                    unset($this->pendingTotalInvalidations[$cacheKey]);
                });
            } else {
                $totalCache->deleteItem($cacheKey);
                $totalCache->deleteItem($versionKey);
            }
        }
    }

    public function total(ContentId $contentId): int
    {
        return $this->totals([$contentId])[(string)$contentId];
    }

    /**
     * @param list<ContentId> $contentIds
     * @return array<string, int>
     */
    public function totals(array $contentIds): array
    {
        $result = [];
        foreach ($contentIds as $contentId) {
            $result[(string)$contentId] = 0;
        }
        if ($result === []) {
            return [];
        }

        if (!$this->totalCache instanceof CacheItemPoolInterface) {
            return $this->databaseTotals($contentIds);
        }

        $contentByCacheKey = [];
        foreach ($contentIds as $requestedContentId) {
            $contentByCacheKey[$this->totalCacheKey($requestedContentId)] = $requestedContentId;
        }

        $versions = [];
        foreach ($contentByCacheKey as $cacheKey => $cachedContentId) {
            $versions[$cacheKey] = $this->totalVersion($cachedContentId);
        }

        $missing = [];
        $items = [];
        foreach ($this->totalCache->getItems(array_keys($contentByCacheKey)) as $cacheKey => $item) {
            $items[$cacheKey] = $item;
        }
        foreach ($contentByCacheKey as $cacheKey => $cachedContentId) {
            $item = $items[$cacheKey] ?? null;
            $cached = $item?->isHit() === true ? $item->get() : null;
            $value = \is_array($cached)
                && ($cached['version'] ?? null) === $versions[$cacheKey]
                && \is_int($cached['value'] ?? null)
                && $cached['value'] >= 0
                ? $cached['value']
                : null;
            if (!isset($this->pendingTotalInvalidations[$cacheKey]) && $value !== null) {
                $result[(string)$cachedContentId] = $value;
                continue;
            }

            $missing[] = $cachedContentId;
        }

        if ($missing === []) {
            return $result;
        }

        $loaded = $this->databaseTotals($missing);
        foreach ($missing as $missingContentId) {
            $value = $loaded[(string)$missingContentId];
            $result[(string)$missingContentId] = $value;
            if (isset($this->pendingTotalInvalidations[$this->totalCacheKey($missingContentId)])) {
                continue;
            }

            $cacheKey = $this->totalCacheKey($missingContentId);
            $version = $versions[$cacheKey];
            if ($this->totalVersion($missingContentId) !== $version) {
                // A concurrent view changed this total after our SELECT. Its
                // invalidation version wins; never overwrite it with this snapshot.
                continue;
            }

            $item = $items[$cacheKey] ?? $this->totalCache->getItem($cacheKey);
            $item->set(['version' => $version, 'value' => $value]);
            $this->totalCache->saveDeferred($item);
        }
        $this->totalCache->commit();

        return $result;
    }

    #[\Override]
    public function clearState(): void
    {
        if (!$this->pdo instanceof PDO || !$this->pdo->inTransaction()) {
            $this->pendingTotalInvalidations = [];
        }
    }

    /**
     * @param list<ContentId> $contentIds
     * @return array<string, int>
     */
    private function databaseTotals(array $contentIds): array
    {
        $result = [];
        $idsByType = [];
        foreach ($contentIds as $contentId) {
            $result[(string)$contentId] = 0;
            $idsByType[$contentId->type->value][$contentId->value] = $contentId->value;
        }

        foreach ($idsByType as $type => $ids) {
            $parameters = ['content_type' => $type];
            $placeholders = [];
            foreach (array_values($ids) as $index => $id) {
                $name = 'view_content_' . $index;
                $placeholders[] = ':' . $name;
                $parameters[$name] = $id;
            }

            $rows = $this->dbLayer
                ->select('content_id, SUM(views) AS view_count')
                ->from(ContentViewSchema::TABLE_NAME)
                ->where('content_type = :content_type')
                ->andWhere('content_id IN (' . implode(', ', $placeholders) . ')')
                ->groupBy('content_id')
                ->execute($parameters)
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $result[$type . ':' . (int)$row['content_id']] = (int)$row['view_count'];
            }
        }

        return $result;
    }

    private function totalCacheKey(ContentId $contentId): string
    {
        return self::TOTAL_CACHE_PREFIX . hash('sha256', (string)$contentId);
    }

    private function totalVersionKey(ContentId $contentId): string
    {
        return self::TOTAL_VERSION_PREFIX . hash('sha256', (string)$contentId);
    }

    private function totalVersion(ContentId $contentId): string
    {
        if (!$this->totalCache instanceof CacheItemPoolInterface) {
            throw new \LogicException('A content-view cache version requires a cache pool.');
        }

        $item = $this->totalCache->getItem($this->totalVersionKey($contentId));
        $value = $item->isHit() ? $item->get() : null;
        if (\is_string($value) && preg_match('/^[a-f0-9]{16}$/D', $value) === 1) {
            return $value;
        }

        $value = bin2hex(random_bytes(8));
        $item->set($value);
        $this->totalCache->save($item);

        return $value;
    }

    /** @return list<int> */
    public function popularPostIds(int $limit): array
    {
        return $this->rankedPostIds($limit, null, false);
    }

    /** @return list<int> */
    public function hotPostIds(int $limit, int $days = 7): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The hot-ranking window must be positive.');
        }

        return $this->rankedPostIds($limit, gmdate('Y-m-d', time() - ($days - 1) * 86400), true);
    }

    /** @return list<int> */
    private function rankedPostIds(int $limit, ?string $sinceDay, bool $includeComments): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The ranking limit must be positive.');
        }

        $viewQuery = $this->dbLayer
            ->select('content_id, SUM(views) AS score')
            ->from(ContentViewSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->groupBy('content_id')
        ;
        if ($sinceDay !== null) {
            $viewQuery->andWhere('day >= :since_day')->setParameter('since_day', $sinceDay);
        }

        $views = [];
        foreach ($viewQuery->execute()->fetchAssocAll() as $row) {
            $views[(int)$row['content_id']] = (int)$row['score'];
        }

        $comments = [];
        if ($includeComments) {
            $commentSince = strtotime(($sinceDay ?? gmdate('Y-m-d')) . ' 00:00:00 UTC');
            $rows = $this->dbLayer
                ->select('content_id, COUNT(*) AS score')
                ->from(CommentSchema::TABLE_NAME)
                ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                ->andWhere('shown = 1')
                ->andWhere('time >= :since')->setParameter('since', $commentSince)
                ->groupBy('content_id')
                ->execute()
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $comments[(int)$row['content_id']] = (int)$row['score'];
            }
        }

        $ranked = [];
        $rows = $this->dbLayer
            ->select('id, featured, published_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $score = (float)($views[$id] ?? 0)
                + ($includeComments ? 5.0 * (float)($comments[$id] ?? 0) : 0.0);
            if ($score <= 0.0) {
                continue;
            }

            if ((bool)$row['featured']) {
                $score *= 1.25;
            }

            $ranked[] = ['id' => $id, 'score' => $score, 'published_at' => (int)$row['published_at']];
        }

        usort($ranked, static function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            return $scoreComparison !== 0
                ? $scoreComparison
                : $right['published_at'] <=> $left['published_at'];
        });

        return array_map(
            static fn(array $row): int => $row['id'],
            array_slice($ranked, 0, $limit),
        );
    }
}
