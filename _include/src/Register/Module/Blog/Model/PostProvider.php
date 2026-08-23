<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\ContentViewRepository;
use Register\Content\TagRepository;
use Register\Url\ContentUrlGenerator;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Core\Pdo\DbLayerException;

readonly class PostProvider
{
    public function __construct(
        private DbLayer         $dbLayer,
        private CommentRepository $commentRepository,
        private TagRepository   $tagRepository,
        private BlogUrlBuilder  $blogUrlBuilder,
        private ContentUrlGenerator $contentUrlGenerator,
        private Viewer          $viewer,
        private ContentViewRepository $contentViewRepository,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function publishedPostCount(): int
    {
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->result()
        ;
    }

    /** @throws DbLayerException */
    public function hasMultiplePublishedAuthors(): bool
    {
        return (int)$this->dbLayer
            ->select('COUNT(DISTINCT author_id)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->result() > 1;
    }

    /** @throws DbLayerException */
    public function hasPublishedPost(string $slug): bool
    {
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('slug = :slug')->setParameter('slug', $slug)
            ->andWhere('published = 1')
            ->execute()
            ->result() > 0;
    }

    /**
     * @throws DbLayerException
     * @return list<array{title: string, link: string}>
     */
    public function allPublishedPostLinks(): array
    {
        $result = $this->dbLayer
            ->select('title', 'slug AS url')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->orderBy('published_at DESC')
            ->execute()
        ;

        $posts = [];
        while ($row = $result->fetchAssoc()) {
            $posts[] = [
                'title' => (string)$row['title'],
                'link'  => $this->contentUrlGenerator->post((string)$row['url']),
            ];
        }

        return $posts;
    }

    /**
     * Returns an array containing info about last N posts
     *
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function lastPostsArray(int $postsNum = 10, int $skip = 0, bool $fakeLastPost = false): array
    {
        if ($fakeLastPost) {
            ++$postsNum;
        }

        // Obtaining last posts
        $rawQueryCount = $this->dbLayer
            ->select('count(*)')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->where('c.content_type = :comment_content_type')
            ->andWhere('c.content_id = p.id')
            ->andWhere('c.shown = 1')
            ->getSql()
        ;

        $rawQueryUser = $this->dbLayer
            ->select('u.name')
            ->from('users AS u')
            ->where('u.id = p.author_id')
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('p.published_at AS create_time, p.date_label AS display_date, p.title, p.body AS text, p.slug AS url, p.id, p.author_id, p.revision, p.comments_enabled AS commented, p.updated_at AS modify_time, p.featured AS favorite')
            ->addSelect('(' . $rawQueryCount . ') AS comment_num')
            ->addSelect('(' . $rawQueryUser . ') AS author, p.series AS label')
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('p.content_type = :post_content_type')
            ->setParameter('post_content_type', ContentType::POST->value)
            ->andWhere('p.published = 1')
            ->setParameter('comment_content_type', ContentType::POST->value)
            ->orderBy('p.published_at DESC')
            ->limit($postsNum)
            ->offset($skip)
            ->execute()
        ;
        $posts = [];
        $mergeLabels = [];
        $labels = [];
        $ids = [];
        $i     = 0;
        while ($row = $result->fetchAssoc()) {
            ++$i;
            $posts[$row['id']] = $row;

            if ($i >= $postsNum && $fakeLastPost) {
                continue;
            }

            $ids[]              = $row['id'];
            $labels[$row['id']] = $row['label'];
            if ((string)$row['label'] !== '') {
                $mergeLabels[$row['label']] = 1;
            }
        }

        if ($i === 0) {
            return [];
        }

        $seeAlso = [];
        $tags = [];
        $this->postsLinks($ids, $mergeLabels, $seeAlso, $tags);
        $viewCounts = $this->viewCounts($ids);

        foreach ($posts as $postId => &$post) {
            $posts[$postId]['see_also'] = [];
            if (isset($labels[$postId]) && (string)$labels[$postId] !== '' && isset($seeAlso[$labels[$postId]])) {
                $labelCopy = $seeAlso[$labels[$postId]];
                if (isset($labelCopy[$postId])) {
                    unset($labelCopy[$postId]);
                }

                $posts[$postId]['see_also'] = $labelCopy;
            }

            $post['tags'] = $tags[$postId] ?? [];
            $post['view_count'] = $viewCounts[(int)$postId] ?? 0;
            if (!isset($post['author'])) {
                $post['author'] = '';
            }

            $link               = $this->contentUrlGenerator->post((string)$post['url']);
            $post['title_link'] = $link;
            $post['link']       = $link;
            $post['time']       = $this->displayDate((int)$post['create_time'], (string)$post['display_date']);
        }

        return $posts;
    }

    /**
     * @param list<int|string> $postIds
     * @return array<int, int>
     */
    public function viewCounts(array $postIds): array
    {
        $contentIds = array_map(
            static fn(int|string $id): ContentId => ContentId::post((int)$id),
            $postIds,
        );
        $totals = $this->contentViewRepository->totals($contentIds);

        $result = [];
        foreach ($contentIds as $contentId) {
            $result[$contentId->value] = $totals[(string)$contentId] ?? 0;
        }

        return $result;
    }

    public function viewCount(int $postId): int
    {
        return $this->contentViewRepository->total(ContentId::post($postId));
    }

    public function randomPublishedPostUrl(): ?string
    {
        $count = $this->publishedPostCount();
        if ($count === 0) {
            return null;
        }

        $slug = $this->dbLayer
            ->select('slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->orderBy('id')
            ->limit(1)
            ->offset(random_int(0, $count - 1))
            ->execute()
            ->result()
        ;

        return \is_string($slug) ? $this->contentUrlGenerator->post($slug) : null;
    }

    public function displayDate(int $createTime, string $displayDate): string
    {
        $displayDate = trim($displayDate);

        return $displayDate !== '' ? $displayDate : $this->viewer->dateAndTime($createTime);
    }

    /**
     * Fetching tags and labels for posts
     *
     * @param array<mixed> $ids
     * @param array<string, int> $labels Series flags keyed by their stored names
     * @param array<mixed> $see_also
     * @param array<mixed> $tags
     * @throws DbLayerException
     */
    public function postsLinks(array $ids, array $labels, array &$see_also, array &$tags): void
    {
        // Processing labels
        if (\count($labels) > 0) {
            $result = $this->dbLayer
                ->select('p.id, p.series AS label, p.title, p.published_at AS create_time, p.slug AS url')
                ->from(ContentSchema::TABLE_NAME . ' AS p')
                ->where("p.content_type = '" . ContentType::POST->value . "'")
                ->andWhere('p.series IN (' . implode(',', array_fill(0, \count($labels), '?')) . ')')
                ->andWhere('p.published = 1')
                ->execute(array_keys($labels))
            ;
            $rows = [];
            $sortArray = [];
            while ($row = $result->fetchAssoc()) {
                $rows[]      = $row;
                $sortArray[] = $row['create_time'];
            }

            array_multisort($sortArray, SORT_DESC, $rows);

            foreach ($rows as $row) {
                $see_also[$row['label']][$row['id']] = [
                    'title' => $row['title'],
                    'link'  => $this->contentUrlGenerator->post((string)$row['url']),
                ];
            }
        }

        // Obtaining tags
        $tags = [];
        $contentIds = array_values(array_map(static fn(mixed $id): ContentId => ContentId::post((int)$id), $ids));
        foreach ($this->tagRepository->findForContent($contentIds) as $contentId => $contentTags) {
            $postId = ContentId::fromString($contentId)->value;
            foreach ($contentTags as $tag) {
                $tags[$postId][] = [
                    'title' => $tag->name,
                    'link'  => $this->blogUrlBuilder->tag($tag->slug),
                ];
            }
        }
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getAllLabels(): array
    {
        $result = $this->dbLayer
            ->select('series')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->groupBy('series')
            ->orderBy('count(series) DESC')
            ->execute()
        ;

        return $result->fetchColumn();
    }

    /**
     * @throws DbLayerException
     */
    public function getCommentNum(int $postId, bool $includeHidden): int
    {
        return $this->commentRepository->count(ContentId::post($postId), $includeHidden);
    }
}
