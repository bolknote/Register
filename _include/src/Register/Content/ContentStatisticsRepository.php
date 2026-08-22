<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;
use Register\Core\Pdo\QueryBuilder\UnionAll;

/** Calculates dashboard statistics from the canonical content and comment tables. */
final readonly class ContentStatisticsRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @throws DbLayerException */
    public function published(ContentType $contentType): ContentStatistics
    {
        return match ($contentType) {
            ContentType::PAGE => $this->publishedPageStatistics(),
            ContentType::POST => $this->publishedFlatStatistics($contentType),
        };
    }

    /** @throws DbLayerException */
    public function editorial(ContentType $contentType, ?int $now = null): ContentEditorialStatistics
    {
        $now    ??= time();
        $result = $this->dbLayer
            ->select('COALESCE(SUM(CASE WHEN published = 0 AND COALESCE(scheduled_at, 0) = 0 THEN 1 ELSE 0 END), 0) AS draft_count')
            ->addSelect('COALESCE(SUM(CASE WHEN published = 0 AND scheduled_at > :scheduled_after THEN 1 ELSE 0 END), 0) AS scheduled_count')
            ->addSelect('COALESCE(SUM(CASE WHEN published = 0 AND scheduled_at > 0 AND scheduled_at <= :overdue_before THEN 1 ELSE 0 END), 0) AS overdue_count')
            ->addSelect('MIN(CASE WHEN published = 0 AND scheduled_at > :next_after THEN scheduled_at ELSE NULL END) AS next_scheduled_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->setParameter('scheduled_after', $now)
            ->setParameter('overdue_before', $now)
            ->setParameter('next_after', $now)
            ->execute()
            ->fetchAssoc()
        ;

        if ($result === false) {
            return new ContentEditorialStatistics(0, 0, 0, null);
        }

        return new ContentEditorialStatistics(
            (int)$result['draft_count'],
            (int)$result['scheduled_count'],
            (int)$result['overdue_count'],
            $result['next_scheduled_at'] === null ? null : (int)$result['next_scheduled_at'],
        );
    }

    /**
     * Only leaf pages are documents in the dashboard count. Sections still contribute
     * their visible comments and unpublished branches remain outside the public tree.
     *
     * @throws DbLayerException
     */
    private function publishedPageStatistics(): ContentStatistics
    {
        $contentTable = ContentSchema::TABLE_NAME;
        $pageType     = ContentType::PAGE->value;
        $baseQuery    = $this->dbLayer
            ->select('id')
            ->from($contentTable)
            ->where('parent_id IS NULL')
            ->andWhere("content_type = '" . $pageType . "'")
            ->andWhere('published = 1')
        ;
        $recursiveQuery = $this->dbLayer
            ->select('content.id')
            ->from($contentTable . ' AS content')
            ->innerJoin('published_page_tree AS tree', 'content.parent_id = tree.id')
            ->where("content.content_type = '" . $pageType . "'")
            ->andWhere('content.published = 1')
        ;
        $result = $this->dbLayer
            ->withRecursive('published_page_tree', new UnionAll($baseQuery, $recursiveQuery))
            ->select('COALESCE(SUM(CASE (' .
                $this->dbLayer->select('COUNT(*)')
                    ->from($contentTable)
                    ->where('parent_id = tree.id')
                    ->andWhere("content_type = '" . $pageType . "'")
                    ->andWhere('published = 1')
                    ->getSql()
                . ') WHEN 0 THEN 1 ELSE 0 END), 0) AS content_count')
            ->addSelect('COALESCE(SUM((' .
                $this->visibleCommentCountSql($pageType, 'tree.id')
                . ')), 0) AS comment_count')
            ->from('published_page_tree AS tree')
            ->execute()
            ->fetchAssoc()
        ;

        return $this->statisticsFromRow($result);
    }

    /** @throws DbLayerException */
    private function publishedFlatStatistics(ContentType $contentType): ContentStatistics
    {
        $type  = $contentType->value;
        $result = $this->dbLayer
            ->select('COUNT(*) AS content_count')
            ->addSelect('COALESCE(SUM((' . $this->visibleCommentCountSql($type, 'content.id') . ')), 0) AS comment_count')
            ->from(ContentSchema::TABLE_NAME . ' AS content')
            ->where("content.content_type = '" . $type . "'")
            ->andWhere('content.published = 1')
            ->execute()
            ->fetchAssoc()
        ;

        return $this->statisticsFromRow($result);
    }

    /** @throws DbLayerException */
    private function visibleCommentCountSql(string $contentType, string $contentIdExpression): string
    {
        return $this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where("content_type = '" . $contentType . "'")
            ->andWhere('content_id = ' . $contentIdExpression)
            ->andWhere('shown = 1')
            ->getSql()
        ;
    }

    /** @param array<mixed>|false $row */
    private function statisticsFromRow(array|false $row): ContentStatistics
    {
        if ($row === false) {
            return new ContentStatistics(0, 0);
        }

        return new ContentStatistics(
            (int)$row['content_count'],
            (int)$row['comment_count'],
        );
    }
}
