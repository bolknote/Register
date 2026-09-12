<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\QueryBuilder\SelectBuilder;
use Register\Core\Pdo\QueryBuilder\UnionAll;
use Register\Url\ContentUrlGenerator;

/** Small, current editorial lists; never loads post bodies or historical comment totals. */
final readonly class BlogOverviewRepository
{
    private const int LIMIT = 5;

    public function __construct(private DbLayer $dbLayer, private ContentUrlGenerator $urls)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(int $now, bool $canWrite, bool $canEditSite, ?int $userId, bool $canModerate): array
    {
        $drafts = [];
        $scheduled = [];
        $queue = ['drafts' => 0, 'scheduled' => 0, 'overdue' => 0];
        if ($canWrite) {
            $queueQuery = $this->unpublished($canEditSite, $userId)
                ->select('SUM(CASE WHEN scheduled_at = 0 THEN 1 ELSE 0 END) AS drafts')
                ->addSelect('SUM(CASE WHEN scheduled_at > :scheduled_after THEN 1 ELSE 0 END) AS scheduled')
                ->addSelect('SUM(CASE WHEN scheduled_at > 0 AND scheduled_at <= :overdue_before THEN 1 ELSE 0 END) AS overdue')
                ->setParameter('scheduled_after', $now)->setParameter('overdue_before', $now)
                ->execute()->fetchAssoc();
            if ($queueQuery !== false) {
                $queue = array_map(intval(...), $queueQuery);
            }

            $drafts = $this->postRows($this->unpublished($canEditSite, $userId)
                ->andWhere('scheduled_at = 0')->orderBy('updated_at DESC', 'id DESC'));
            $scheduled = $this->postRows($this->unpublished($canEditSite, $userId)
                ->andWhere('scheduled_at > 0')->orderBy('scheduled_at', 'id'));

            // Historical imports may use published=1 with a future publication time.
            // Read this indexed range separately, and merge at most two five-row lists.
            $futureQuery = $this->editablePosts($canEditSite, $userId)
                ->andWhere('published = 1')->andWhere('published_at > :future_after')
                ->setParameter('future_after', $now);
            $futureCount = (int)(clone $futureQuery)->select('COUNT(*)')->execute()->result();
            $queue['scheduled'] += $futureCount;
            if ($futureCount > 0) {
                $futureRows = $this->postRows($futureQuery->orderBy('published_at', 'id'));
                foreach ($futureRows as &$row) {
                    $row['scheduled_at'] = $row['published_at'];
                }

                unset($row);

                $scheduled = array_merge($scheduled, $futureRows);
                usort($scheduled, static function (array $left, array $right): int {
                    $timeOrder = (int)$left['scheduled_at'] <=> (int)$right['scheduled_at'];
                    return $timeOrder !== 0 ? $timeOrder : (int)$left['id'] <=> (int)$right['id'];
                });
                $scheduled = array_slice($scheduled, 0, self::LIMIT);
            }
        }

        $recent = $this->postRows($this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'post'")->andWhere('published = 1')
            ->andWhere('published_at <= :now')->setParameter('now', $now)
            ->orderBy('published_at DESC', 'id DESC'));

        $pendingQuery = $this->commentQuery($now)->andWhere('c.shown = 0')->andWhere('c.sent = 0');
        $pendingCount = $canModerate ? (int)(clone $pendingQuery)->select('COUNT(*)')->execute()->result() : 0;

        return [
            'queue' => $queue,
            'drafts' => $drafts,
            'scheduled' => $scheduled,
            'recent' => $recent,
            'pendingCount' => $pendingCount,
            'pending' => $canModerate ? $this->commentRows($pendingQuery) : [],
            'comments' => $this->commentRows($this->commentQuery($now)->andWhere('c.shown = 1')),
        ];
    }

    private function unpublished(bool $canEditSite, ?int $userId): SelectBuilder
    {
        return $this->editablePosts($canEditSite, $userId)->andWhere('published = 0');
    }

    private function editablePosts(bool $canEditSite, ?int $userId): SelectBuilder
    {
        $query = $this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'post'");
        if (!$canEditSite) {
            $query->andWhere('author_id = :author')->setParameter('author', $userId ?? 0);
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    private function postRows(SelectBuilder $query): array
    {
        $rows = $query->select('id, title, slug, published_at, scheduled_at, updated_at')
            ->limit(self::LIMIT)->execute()->fetchAssocAll();
        foreach ($rows as &$row) {
            $slug = (string)$row['slug'];
            $row['url'] = in_array('', explode('/', $slug), true) ? null : $this->urls->post($slug);
        }

        return array_values($rows);
    }

    private function commentQuery(int $now): SelectBuilder
    {
        // A published page is unreachable if any ancestor is hidden. Use the same
        // visible tree for both the moderation badge and its bounded list.
        $roots = $this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('published = 1')->andWhere('parent_id IS NULL');
        $children = $this->dbLayer->select('child.id')->from(ContentSchema::TABLE_NAME . ' AS child')
            ->innerJoin('overview_visible_pages AS parent', 'parent.id = child.parent_id')
            ->where("child.content_type = 'page'")->andWhere('child.published = 1');

        return $this->dbLayer->withRecursive('overview_visible_pages', new UnionAll($roots, $children))
            ->select('c.id')->from(CommentSchema::TABLE_NAME . ' AS c')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS p', 'p.id = c.content_id AND p.content_type = c.content_type')
            ->where('c.deleted = 0')->andWhere('p.published = 1')
            ->andWhere("(p.content_type = 'page' OR p.published_at <= :published_before)")
            ->andWhere("(p.content_type = 'post' OR p.id IN (SELECT id FROM overview_visible_pages))")
            ->andWhere("(p.content_type = 'page' OR (p.slug <> '' AND p.slug NOT LIKE '/%' AND p.slug NOT LIKE '%/' AND p.slug NOT LIKE '%//%'))")
            ->setParameter('published_before', $now);
    }

    /** @return list<array<string, mixed>> */
    private function commentRows(SelectBuilder $query): array
    {
        $rows = $query->select('c.id, c.content_id, c.content_type, c.nick, c.text, c.time, p.title, p.slug')
            ->orderBy('c.time DESC', 'c.id DESC')->limit(self::LIMIT)->execute()->fetchAssocAll();
        $result = [];
        foreach ($rows as $row) {
            $path = $row['content_type'] === ContentType::POST->value
                ? $this->urls->postPath((string)$row['slug'])
                : $this->urls->path(new ContentId(ContentType::PAGE, (int)$row['content_id']), true);
            if ($path === null) {
                continue;
            }

            $row['url'] = $this->urls->linkPath($path) . '#comment-' . (int)$row['id'];
            $plain = html_entity_decode(strip_tags((string)$row['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
            $row['snippet'] = mb_strlen($plain) > 180 ? mb_substr($plain, 0, 179) . '…' : $plain;
            unset($row['text']);
            $result[] = $row;
        }

        return $result;
    }
}
