<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;

/** Writes daily aggregates and builds popular/hot post rankings without storing visitor data. */
final readonly class ContentViewRepository
{
    public function __construct(private DbLayer $dbLayer)
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
    }

    public function total(ContentId $contentId): int
    {
        return (int)$this->dbLayer
            ->select('COALESCE(SUM(views), 0)')
            ->from(ContentViewSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
            ->result()
        ;
    }

    /**
     * @param list<ContentId> $contentIds
     * @return array<string, int>
     */
    public function totals(array $contentIds): array
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
