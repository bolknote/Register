<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Url\ContentUrlGenerator;

/** Builds one post index instead of repeating cross-post SQL for every cached route. */
final readonly class PostPageContextProvider
{
    public function __construct(
        private DbLayer             $dbLayer,
        private ContentUrlGenerator $contentUrlGenerator,
        private BlogPageCache       $pageCache,
    ) {
    }

    public function forPost(int $postId): ?PostPageContext
    {
        return $this->pageCache->postPageContextIndex($this->buildIndex(...))->forPost($postId);
    }

    private function buildIndex(): PostPageContextIndex
    {
        $result = $this->dbLayer
            ->select('p.id, p.title, p.published_at, p.slug, p.series, p.author_id, u.name AS author')
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->leftJoin('users AS u', 'u.id = p.author_id')
            ->where('p.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('p.published = 1')
            ->orderBy('p.published_at ASC, p.id ASC')
            ->execute()
        ;

        $posts = [];
        $series = [];
        $idsByTime = [];
        $monthPosts = [];
        $authors = [];
        while (($row = $result->fetchAssoc()) !== false) {
            $id = (int)$row['id'];
            $publishedAt = (int)$row['published_at'];
            $slug = (string)$row['slug'];
            $seriesName = (string)$row['series'];
            $monthKey = date('Y-n', $publishedAt);
            $day = (int)date('j', $publishedAt);

            $posts[$id] = [
                (string)$row['title'],
                $this->contentUrlGenerator->post($slug),
                (string)($row['author'] ?? ''),
                $seriesName,
                $publishedAt,
                $slug,
            ];
            $idsByTime[$publishedAt][] = $id;
            $monthPosts[$monthKey][$day][] = $id;
            if ($seriesName !== '') {
                $series[$seriesName][] = $id;
            }

            if ($row['author_id'] !== null) {
                $authors[(int)$row['author_id']] = true;
            }
        }

        foreach ($series as &$postIds) {
            $postIds = array_reverse($postIds);
        }

        unset($postIds);

        $back = [];
        $forward = [];
        $timeGroups = array_values($idsByTime);
        foreach ($timeGroups as $groupIndex => $postIds) {
            $previous = $groupIndex > 0 ? $timeGroups[$groupIndex - 1] : [];
            $next = $timeGroups[$groupIndex + 1] ?? [];
            foreach ($postIds as $postId) {
                if ($previous !== []) {
                    $back[$postId] = $previous[array_key_last($previous)];
                }

                if ($next !== []) {
                    $forward[$postId] = $next[0];
                }
            }
        }

        return new PostPageContextIndex(
            $posts,
            $series,
            $back,
            $forward,
            $monthPosts,
            \count($authors) > 1,
        );
    }
}
