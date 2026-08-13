<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use S2\Cms\Pdo\DbLayerException;

readonly class PostProvider
{
    public function __construct(
        private DbLayer         $dbLayer,
        private CommentRepository $commentRepository,
        private TagRepository   $tagRepository,
        private BlogUrlBuilder  $blogUrlBuilder,
        private ArticleProvider $articleProvider,
        private Viewer          $viewer,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function publishedPostCount(): int
    {
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->execute()
            ->result()
        ;
    }

    /**
     * @throws DbLayerException
     * @return list<array{title: string, link: string}>
     */
    public function allPublishedPostLinks(): array
    {
        $result = $this->dbLayer
            ->select('title', 'url')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->orderBy('create_time DESC')
            ->execute()
        ;

        $posts = [];
        while ($row = $result->fetchAssoc()) {
            $posts[] = [
                'title' => (string)$row['title'],
                'link'  => $this->blogUrlBuilder->post((string)$row['url']),
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
            ->where('u.id = p.user_id')
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('p.create_time, p.display_date, p.title, p.text, p.url, p.id, p.commented, p.modify_time, p.favorite')
            ->addSelect('(' . $rawQueryCount . ') AS comment_num')
            ->addSelect('(' . $rawQueryUser . ') AS author, p.label')
            ->from('s2_blog_posts AS p')
            ->where('p.published = 1')
            ->setParameter('comment_content_type', ContentType::POST->value)
            ->orderBy('p.create_time DESC')
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
            if (!isset($post['author'])) {
                $post['author'] = '';
            }

            $link               = $this->blogUrlBuilder->post($post['url']);
            $post['title_link'] = $link;
            $post['link']       = $link;
            $post['time']       = $this->displayDate((int)$post['create_time'], (string)$post['display_date']);
        }

        return $posts;
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
     * @param array<int, int> $labels Label flags
     * @param array<mixed> $see_also
     * @param array<mixed> $tags
     * @throws DbLayerException
     */
    public function postsLinks(array $ids, array $labels, array &$see_also, array &$tags): void
    {
        // Processing labels
        if (\count($labels) > 0) {
            $result = $this->dbLayer
                ->select('p.id, p.label, p.title, p.create_time, p.url')
                ->from('s2_blog_posts AS p')
                ->where('p.label IN (' . implode(',', array_fill(0, \count($labels), '?')) . ')')
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
                    'link'  => $this->blogUrlBuilder->post($row['url']),
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
     */
    public function checkUrlStatus(int $postId, string $url): string
    {
        if ($url === '') {
            return 'empty';
        }

        if (
            str_contains($url, '/')
            || $this->blogUrlBuilder->isReservedPostSlug($url)
            || $this->articleProvider->articleFromPath(
                $this->blogUrlBuilder->pathPrefix() . '/' . rawurlencode($url),
                false
            ) !== null
        ) {
            return 'unavailable';
        }

        $result = $this->dbLayer
            ->select('COUNT(*)')
            ->from('s2_blog_posts')
            ->where('url = :url')
            ->setParameter('url', $url)
            ->andWhere('id <> :id')
            ->setParameter('id', $postId)
            ->execute()
        ;

        if ((int)$result->result() > 0) {
            return 'not_unique';
        }

        return 'ok';
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getAllLabels(): array
    {
        $result = $this->dbLayer
            ->select('label')
            ->from('s2_blog_posts')
            ->groupBy('label')
            ->orderBy('count(label) DESC')
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
