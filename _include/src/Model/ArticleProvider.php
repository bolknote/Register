<?php
/**
 * Updates search index when a visible article has been changed.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Url\ContentUrlGenerator;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\QueryBuilder\UnionAll;
use S2\Cms\Template\Viewer;
use S2\Cms\Pdo\DbLayerException;

readonly class ArticleProvider
{
    public const int ROOT_ID = 0;

    public function __construct(
        private DbLayer     $dbLayer,
        private CommentRepository $commentRepository,
        private ContentUrlGenerator $contentUrlGenerator,
        private UrlBuilder  $urlBuilder,
        private Viewer      $viewer,
        private StringProxy $favoriteUrl,
        private BoolProxy   $useHierarchy,
    ) {
    }

    /**
     * Fetches hierarchical URLs for several articles. This is done by minimal number of SQL queries,
     * as if there were only one article.
     *
     * Returns an array containing full URLs, keys are preserved.
     * If somewhere is a hidden parent, the URL is removed from the returning array.
     *
     * Actually it's one of the best things in S2! :)
     *
     * @throws DbLayerException
     * @param array<mixed> $parentIds
     * @param string[] $urls
     * @return array<mixed>
     */
    public function getFullUrlsForArticles(array $parentIds, array $urls): array
    {
        if (!$this->useHierarchy->get()) {
            // Flat urls
            foreach ($urls as $k => $url) {
                $urls[$k] = '/' . $url;
            }

            return $urls;
        }

        return $this->contentUrlGenerator->completePublishedPagePaths($parentIds, $urls);
    }

    /**
     * Returns the title of the main page.
     *
     * @throws DbLayerException
     */
    public function mainPageTitle(): string
    {
        // TODO cache?
        $result = $this->dbLayer
            ->select('title')
            ->from(ContentSchema::TABLE_NAME)
            ->where("content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('parent_id IS NULL')
            ->execute()
        ;

        $title = $result->result();
        if (!\is_string($title)) {
            throw new \UnexpectedValueException('The published root page is missing from the content table.');
        }

        return $title;
    }

    /**
     * Fetching last articles info (for template placeholders and RSS)
     *
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function lastArticlesList(?int $limit = 5): array
    {
        $raw_query_child_num = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a2')
            ->where('a2.parent_id = a.id')
            ->andWhere("a2.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a2.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $raw_query_user = $this->dbLayer
            ->select('u.name')
            ->from('users AS u')
            ->where('u.id = a.author_id')
            ->getSql()
        ;

        $qb = $this->dbLayer
            ->select('a.id, a.title, a.published_at AS create_time, a.updated_at AS modify_time, a.excerpt, a.featured AS favorite, a.slug AS url')
            ->addSelect('a.parent_id, a1.title AS parent_title, a1.slug AS p_url, (' . $raw_query_user . ') AS author')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS a1', 'a1.id = a.parent_id')
            ->where('(' . $raw_query_child_num . ') IS NULL')
            ->andWhere("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.published_at <> 0 OR a.updated_at <> 0')
            ->andWhere('a.published = 1')
            ->orderBy('a.published_at DESC')
        ;

        if ($limit !== null) {
            $qb->limit($limit);
        }

        $result = $qb->execute();
        $last = [];
        $urls = [];
        $parentIds = [];
        for ($i = 0; $row = $result->fetchAssoc(); ++$i) {
            $urls[$i]      = rawurlencode($row['url']);
            $parentIds[$i] = $row['parent_id'];

            $last[$i]['title']        = $row['title'];
            $last[$i]['parent_title'] = $row['parent_title'];
            $last[$i]['p_url']        = $row['p_url'];
            $last[$i]['time']         = $row['create_time'];
            $last[$i]['modify_time']  = $row['modify_time'];
            $last[$i]['favorite']     = $row['favorite'];
            $last[$i]['text']         = $row['excerpt'];
            $last[$i]['author']       = $row['author'] ?? '';
        }

        $urls = $this->getFullUrlsForArticles($parentIds, $urls);

        foreach (array_keys($last) as $k) {
            if (isset($urls[$k])) {
                $last[$k]['rel_path'] = $urls[$k];
            } else {
                unset($last[$k]);
            }
        }

        return $last;
    }

    /**
     * Formatting last articles (for template placeholders)
     *
     * @throws DbLayerException
     */
    public function lastArticlesPlaceholder(int $limit): string
    {
        $articles = $this->lastArticlesList($limit);

        $output = '';
        if (\count($articles) > 0) {
            $useHierarchy = $this->useHierarchy->get();
            $favoriteLink = $this->urlBuilder->link('/' . rawurlencode($this->favoriteUrl->get()) . '/');
            foreach ($articles as &$item) {
                $parentPath            = $useHierarchy
                    ? preg_replace('#/\\K[^/]*$#', '', (string)$item['rel_path']) ?? throw new \RuntimeException('Unable to build an article parent path.')
                    : '/' . $item['p_url'];
                $item['date']          = $this->viewer->date($item['time']);
                $item['link']          = $this->urlBuilder->link($item['rel_path']);
                $item['parent_link']   = $this->urlBuilder->link($parentPath);
                $item['favorite_link'] = $favoriteLink;

                $output .= $this->viewer->render('last_articles_item', $item);
            }

            unset($item);
        }

        return $output;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getTemplateList(): array
    {
        $result        = $this->dbLayer
            ->select('DISTINCT a.template')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
            ->execute()
        ;
        $usedTemplates = $result->fetchColumn();

        return array_values(array_unique(array_merge([
            '',
            'site.php',
            'mainpage.php',
            'back_forward.php',
        ], $usedTemplates)));
    }

    /**
     * @throws DbLayerException
     */
    public function pathFromId(int $id, bool $visibleForAll = false): ?string
    {
        if ($id < 0) {
            return null;
        }

        if ($id === self::ROOT_ID) {
            return '';
        }

        return $this->contentUrlGenerator->pagePath($id, $visibleForAll);
    }


    /**
     * @throws DbLayerException
     */
    public function findInheritedTemplate(int $id, bool $visibleForAll = false): string
    {
        if ($id <= 0) {
            return '';
        }

        $baseQuery      = $this->dbLayer
            ->select('id, template, parent_id, 1 AS level')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')
            ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
        ;
        $recursiveQuery = $this->dbLayer
            ->select('a.id, a.template, a.parent_id, p.level + 1')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->innerJoin('parent_cte AS p', 'a.id = p.parent_id')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
        ;
        if ($visibleForAll) {
            $baseQuery->andWhere('published = 1');
            $recursiveQuery->andWhere('a.published = 1');
        }

        $result = $this->dbLayer
            ->withRecursive('parent_cte', new UnionAll($baseQuery, $recursiveQuery))
            ->select('template')
            ->from('parent_cte')
            ->where("template != ''")
            ->andWhere('id != :skip_id')
            ->orderBy('level ASC')
            ->limit(1)
            ->setParameter('id', $id)
            ->setParameter('skip_id', $id)
            ->execute()
        ;

        $row = $result->fetchAssoc();

        return $row === false ? '' : (string)$row['template'];
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    public function articleFromPath(string $path, bool $publishedOnly): ?array
    {
        $pathArray = explode('/', $path);   // e.g. []/[dir1]/[dir2]/[dir3]/[file1]
        $pathArray = array_map(rawurldecode(...), $pathArray);

        // Remove the last empty element
        if ($pathArray[\count($pathArray) - 1] === '') {
            unset($pathArray[\count($pathArray) - 1]);
        }

        if (!$this->useHierarchy->get()) {
            $pathArray = \count($pathArray) > 0 ? [$pathArray[1]] : [''];
        }

        $id        = self::ROOT_ID;
        $title     = null;
        $commented = null;

        // Walking through page parents
        foreach ($pathArray as $pathItem) {
            $qb = $this->dbLayer
                ->select('a.id, a.title, a.comments_enabled AS commented')
                ->from(ContentSchema::TABLE_NAME . ' AS a')
                ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
                ->andWhere('slug = :url')->setParameter('url', $pathItem)
            ;

            if ($this->useHierarchy->get()) {
                if ($id === self::ROOT_ID) {
                    $qb->andWhere('parent_id IS NULL');
                } else {
                    $qb->andWhere('parent_id = :id')->setParameter('id', $id);
                }
            }

            if ($publishedOnly) {
                $qb->andWhere('published = 1');
            }

            $row = $qb->execute()->fetchRow();
            if (!\is_array($row)) {
                return null;
            }

            [$id, $title, $commented] = $row;
        }

        return ['id' => $id, 'title' => $title, 'commented' => $commented];
    }

    /** @throws DbLayerException */
    public function templateStatus(int $id): string
    {
        $template = $this->dbLayer
            ->select('template')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
            ->execute()
            ->result()
        ;

        return \is_string($template) && (!$this->useHierarchy->get() || $template !== '') ? 'ok' : 'empty';
    }

    /**
     * @throws DbLayerException
     */
    public function getCommentNum(int $id, bool $includeHidden): int
    {
        return $this->commentRepository->count(ContentId::page($id), $includeHidden);
    }
}
