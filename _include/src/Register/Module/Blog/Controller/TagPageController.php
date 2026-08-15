<?php

declare(strict_types = 1);

/**
 * Blog posts for a specified tag.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\Exception\NotFoundException;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\QueryBuilder\SelectBuilder;
use S2\Cms\Template\HtmlTemplate;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;


class TagPageController extends BlogController
{
    public function __construct(
        DbLayer               $dbLayer,
        CalendarBuilder       $calendarBuilder,
        BlogUrlBuilder        $blogUrlBuilder,
        ArticleProvider       $articleProvider,
        PostProvider          $postProvider,
        ContentUrlGenerator   $contentUrlGenerator,
        UrlBuilder            $urlBuilder,
        TranslatorInterface   $translator,
        HtmlTemplateProvider  $templateProvider,
        Viewer                $viewer,
        StringProxy           $blogTitle,
        BoolProxy             $showComments,
        BoolProxy             $enabledComments,
        private readonly TagRepository $tagRepository,
        private readonly BoolProxy $useHierarchy
    ) {
        parent::__construct(
            $dbLayer,
            $calendarBuilder,
            $blogUrlBuilder,
            $articleProvider,
            $postProvider,
            $contentUrlGenerator,
            $urlBuilder,
            $translator,
            $templateProvider,
            $viewer,
            $blogTitle,
            $showComments,
            $enabledComments,
        );
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        $params = $request->attributes->all();

        if ($template->hasPlaceholder('<!-- s2_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- s2_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $tag = $params['tag'];

        $tagEntity = $this->tagRepository->findBySlug((string)$tag);
        if (!$tagEntity instanceof \Register\Content\Tag) {
            throw new NotFoundException();
        }

        $tagDescription = $tagEntity->description;
        $tagName        = $tagEntity->name;
        $tagUrl         = $tagEntity->slug;

        if ($params['slash'] !== '/') {
            return new RedirectResponse($this->blogUrlBuilder->tag($tagUrl), Response::HTTP_MOVED_PERMANENTLY);
        }

        $art_links = $this->articles_by_tag($tagEntity->id);
        if (\count($art_links) > 0) {
            $tagDescription .= '<p>' . $this->translator->trans('Posts by tag') . '<br />' . implode('<br />', $art_links) . '</p>';
        }

        if ($tagDescription !== '') {
            $tagDescription .= '<hr />';
        }

        $postIds = array_map(
            static fn(ContentId $contentId): int => $contentId->value,
            $this->tagRepository->findPublishedContentIds($tagEntity->id, ContentType::POST),
        );
        $output = $this->getPosts(
            static function (SelectBuilder $qb) use ($postIds): SelectBuilder {
                if ($postIds === []) {
                    return $qb->andWhere('1 = 0');
                }

                $parameters = [];
                $placeholders = [];
                foreach ($postIds as $index => $postId) {
                    $parameter               = 'tag_post_' . $index;
                    $parameters[$parameter] = $postId;
                    $placeholders[]          = ':' . $parameter;
                }

                $qb->andWhere('p.id IN (' . implode(', ', $placeholders) . ')');
                foreach ($parameters as $parameter => $postId) {
                    $qb->setParameter($parameter, $postId);
                }

                return $qb;
            },
            false,
        );

        if ($output === '' && $art_links === []) {
            throw new NotFoundException();
        }

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        $template->addBreadCrumb($this->translator->trans('Tags'), $this->blogUrlBuilder->tags());
        $template->addBreadCrumb($tagName);

        $template
            ->putInPlaceholder('head_title', s2_htmlencode($tagName))
            ->putInPlaceholder('title', $this->viewer->render('tag_title', ['title' => $tagName]))
            ->putInPlaceholder('text', $tagDescription . $output)
        ;

        $template->setLink('up', $this->blogUrlBuilder->tags());

        return null;
    }

    /**
     * Returns links to blog posts with the specified tag.
     * @throws DbLayerException
     * @return string[]
     */
    private function articles_by_tag(int $tag_id): array
    {
        $rawQuery = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a1')
            ->where('a1.parent_id = a.id')
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a1.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $title = [];
        $urls = [];
        $parentIds = [];
        $useHierarchy = $this->useHierarchy->get();

        $pageIds = array_map(
            static fn(ContentId $contentId): int => $contentId->value,
            $this->tagRepository->findPublishedContentIds($tag_id, ContentType::PAGE),
        );
        if ($pageIds !== []) {
            $result = $this->dbLayer
                ->select('a.id, a.slug AS url, a.title, a.parent_id')
                ->addSelect('(' . $rawQuery . ') IS NOT NULL AS children_exist')
                ->from(ContentSchema::TABLE_NAME . ' AS a')
                ->where('a.id IN (' . implode(', ', array_fill(0, \count($pageIds), '?')) . ')')
                ->andWhere("a.content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('a.published = 1')
                ->execute($pageIds)
            ;
            while ($row = $result->fetchAssoc()) {
                $urls[]      = urlencode($row['url']) . ($useHierarchy && (bool)$row['children_exist'] ? '/' : '');
                $parentIds[] = $row['parent_id'];
                $title[]     = $row['title'];
            }
        }

        $urls = $this->articleProvider->getFullUrlsForArticles($parentIds, $urls);

        foreach ($urls as $k => $v) {
            $urls[$k] = '<a href="' . $this->urlBuilder->link($v) . '">' . $title[$k] . '</a>';
        }

        return $urls;
    }
}
