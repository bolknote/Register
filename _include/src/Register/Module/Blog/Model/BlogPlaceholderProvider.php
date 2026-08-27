<?php

declare(strict_types = 1);

/**
 * Content for blog placeholders.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Model;

use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Url\ContentUrlGenerator;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Core\Pdo\DbLayerException;

readonly class BlogPlaceholderProvider
{
    public function __construct(
        private DbLayer             $dbLayer,
        private TagRepository       $tagRepository,
        private BlogUrlBuilder      $blogUrlBuilder,
        private ContentUrlGenerator $contentUrlGenerator,
        private TranslatorInterface $translator,
        private Viewer              $viewer,
        private RequestStack        $requestStack,
        private BlogPageCache       $pageCache,
        private BoolProxy           $showComments,
        private IntProxy            $maxItems,
        private string              $urlPrefix,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @return array<mixed>
     */
    public function getBlogNavigationData(): array
    {
        $request_uri = $this->urlPrefix . ($this->requestStack->getCurrentRequest()?->getPathInfo() ?? '');
        $linkIsCurrent = (static fn(mixed $navigationItem): bool => \is_array($navigationItem)
            && isset($navigationItem['link'])
            && \is_string($navigationItem['link'])
            && $navigationItem['link'] === $request_uri);

        $result = $this->pageCache->navigation(function (): array {
            $blogNavigation = ['title' => $this->translator->trans('Navigation')];

            // Last posts on the blog main page
            $maxItems = $this->maxItems->get();
            $blogNavigation['last'] = [
                'title' => \sprintf($this->translator->trans('Nav last'), $maxItems > 0 ? $maxItems : 10),
                'link'  => $this->blogUrlBuilder->main(),
            ];

            // Check for favorite posts
            $result = $this->dbLayer->select('1')
                ->from(ContentSchema::TABLE_NAME)
                ->where('content_type = :navigation_content_type')
                ->setParameter('navigation_content_type', ContentType::POST->value)
                ->andWhere('published = 1')
                ->andWhere('featured = 1')
                ->limit(1)
                ->execute()
            ;

            if ($result->fetchRow() !== false) {
                $blogNavigation['favorite'] = [
                    'title' => $this->translator->trans('Nav favorite'),
                    'link'  => $this->blogUrlBuilder->favorite(),
                ];
            }

            $blogNavigation['popular'] = [
                'title' => $this->translator->trans('Nav popular'),
                'link'  => $this->blogUrlBuilder->popular(),
            ];
            $blogNavigation['hot'] = [
                'title' => $this->translator->trans('Nav hot'),
                'link'  => $this->blogUrlBuilder->hot(),
            ];
            $blogNavigation['random'] = [
                'title' => $this->translator->trans('Nav random'),
                'link'  => $this->blogUrlBuilder->random(),
            ];

            // Fetch important tags
            $blogNavigation['tags_header'] = [
                'title' => $this->translator->trans('Nav tags'),
                'link'  => $this->blogUrlBuilder->tags(),
            ];

            $result = $this->dbLayer->select('t.name, t.url, count(t.id)')
                ->from('tags AS t')
                ->innerJoin(ContentTagSchema::TABLE_NAME . ' AS pt', 't.id = pt.tag_id')
                ->innerJoin(ContentSchema::TABLE_NAME . ' AS p', 'p.id = pt.content_id')
                ->where('t.register_blog_important = 1')
                ->andWhere("pt.content_type = '" . ContentType::POST->value . "'")
                ->andWhere("p.content_type = '" . ContentType::POST->value . "'")
                ->andWhere('p.published = 1')
                ->groupBy('t.id')
                ->orderBy('3 DESC')
                ->execute()
            ;

            $tags = [];
            while ($tag = $result->fetchAssoc()) {
                $tags[] = [
                    'title' => $tag['name'],
                    'link'  => $this->blogUrlBuilder->tag($tag['url']),
                ];
            }

            $blogNavigation['tags'] = $tags;

            return $blogNavigation;
        });

        foreach ($result as &$item) {
            if (\is_array($item)) {
                if (array_is_list($item)) {
                    foreach ($item as &$sub_item) {
                        if (\is_array($sub_item)) {
                            $sub_item['is_current'] = $linkIsCurrent($sub_item);
                        }
                    }

                    unset($sub_item);
                } else {
                    $item['is_current'] = $linkIsCurrent($item);
                }
            }
        }

        unset($item);

        return $result;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getRecentComments(): array
    {
        if (!$this->showComments->get()) {
            return [];
        }

        $raw_query1 = $this->dbLayer
            ->select('count(*) + 1')
            ->from(CommentSchema::TABLE_NAME . ' AS c1')
            ->where('c1.shown = 1')
            ->andWhere('c1.content_type = c.content_type')
            ->andWhere('c1.content_id = c.content_id')
            ->andWhere('c1.time < c.time')
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('time, p.slug AS url, title, nick, p.published_at AS create_time, (' . $raw_query1 . ') AS count')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS p', 'c.content_id = p.id')
            ->where('p.comments_enabled = 1')
            ->andWhere('p.published = 1')
            ->andWhere('p.content_type = :post_content_type')->setParameter('post_content_type', ContentType::POST->value)
            ->andWhere('c.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('c.shown = 1')
            ->orderBy('time DESC')
            ->limit(5)
            ->execute()
        ;

        $output      = [];
        $request_uri = $this->urlPrefix . ($this->requestStack->getCurrentRequest()?->getPathInfo() ?? '');
        while ($row = $result->fetchAssoc()) {
            $cur_url  = $this->contentUrlGenerator->post((string)$row['url']);
            $output[] = [
                'title'      => $row['title'],
                'link'       => $cur_url . '#' . $row['count'],
                'author'     => $row['nick'],
                'is_current' => $request_uri === $cur_url,
            ];
        }

        return $output;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getRecentDiscussions(): array
    {
        if (!$this->showComments->get()) {
            return [];
        }

        $rawQuery = $this->dbLayer
            ->select('c.content_id AS post_id, COUNT(c.content_id) AS comment_num, MAX(c.id) AS max_id, MIN(c.time) AS min_time')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->where('c.content_type = :content_type')
            ->andWhere('c.shown = 1')
            ->andWhere('c.time > :time')
            ->groupBy('c.content_id')
            ->orderBy('comment_num DESC')
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('p.published_at AS create_time, p.slug AS url, p.title, c1.comment_num AS comment_num, c1.min_time, c2.nick, c2.time')
            ->from(ContentSchema::TABLE_NAME . ' AS p, (' . $rawQuery . ') AS c1')
            ->innerJoin(CommentSchema::TABLE_NAME . ' AS c2', 'c2.id = c1.max_id')
            ->where('c1.post_id = p.id')
            ->andWhere('p.content_type = :post_content_type')
            ->andWhere('p.comments_enabled = 1')
            ->andWhere('p.published = 1')
            ->setParameter('content_type', ContentType::POST->value)
            ->setParameter('post_content_type', ContentType::POST->value)
            ->setParameter('time', strtotime('-1 month midnight'))
            ->limit(10)
            ->execute()
        ;

        $output      = [];
        $request_uri = $this->urlPrefix . ($this->requestStack->getCurrentRequest()?->getPathInfo() ?? '');
        $invalidateAt = null;
        while ($row = $result->fetchAssoc()) {
            $cur_url  = $this->contentUrlGenerator->post((string)$row['url']);
            $output[] = [
                'title'      => $row['title'],
                'link'       => $cur_url,
                'hint'       => $row['nick'] . ' (' . $this->viewer->dateAndTime($row['time']) . ')',
                'is_current' => $request_uri === $cur_url,
            ];
            $boundary = $this->discussionInvalidationAt((int)$row['min_time']);
            $invalidateAt = $invalidateAt === null ? $boundary : min($invalidateAt, $boundary);
        }
        if ($invalidateAt !== null) {
            $this->pageCache->invalidateCurrentResponseAt($invalidateAt);
        }

        return $output;
    }

    private function discussionInvalidationAt(int $oldestCommentTime): int
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $boundary = (new \DateTimeImmutable('now', $zone))->modify('tomorrow')->setTime(0, 0);
        for ($day = 0; $day < 40; ++$day) {
            $timestamp = $boundary->getTimestamp();
            $cutoff = strtotime('-1 month midnight', $timestamp);
            if ($cutoff >= $oldestCommentTime) {
                return $timestamp;
            }

            $boundary = $boundary->modify('+1 day');
        }

        throw new \LogicException('Unable to calculate the recent-discussion cache boundary.');
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getBlogTagsForArticle(int $articleId): array
    {
        $links = [];
        $usedInPosts = [];
        foreach ($this->tagRepository->findPublishedUsage(ContentType::POST) as $usage) {
            $usedInPosts[$usage->tag->id] = true;
        }

        $tagsByContent = $this->tagRepository->findForContent([ContentId::page($articleId)]);
        foreach ($tagsByContent['page:' . $articleId] as $tag) {
            if (!isset($usedInPosts[$tag->id])) {
                continue;
            }

            $links[] = [
                'title' => $tag->name,
                'link'  => $this->blogUrlBuilder->tag($tag->slug),
            ];
        }

        return $links;
    }
}
