<?php
/**
 * Displays the list of pages and excerpts for a specified tag.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Framework\Exception\NotFoundException;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

readonly class PageTag implements ControllerInterface
{
    public function __construct(
        private DbLayer              $dbLayer,
        private TagRepository        $tagRepository,
        private ArticleProvider      $articleProvider,
        private UrlBuilder           $urlBuilder,
        private TranslatorInterface  $translator,
        private HtmlTemplateProvider $htmlTemplateProvider,
        private Viewer               $viewer,
        private StringProxy          $tagsUrlFragment,
        private StringProxy          $favoriteUrl,
        private BoolProxy            $useHierarchy,
    ) {
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     * @throws NotFoundException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        $name     = $request->attributes->get('name');
        $hasSlash = $request->attributes->get('slash') === '/';

        // Tag preview
        $tag = $this->tagRepository->findBySlug((string)$name);
        if (!$tag instanceof \Register\Content\Tag) {
            throw new NotFoundException();
        }

        $tagDescription = $tag->description;
        $tagName        = $tag->name;
        $tagUrl         = $tag->slug;

        if (!$hasSlash) {
            return new RedirectResponse(
                $this->urlBuilder->link('/' . rawurlencode($this->tagsUrlFragment->get()) . '/' . rawurlencode($tagUrl) . '/'),
                Response::HTTP_MOVED_PERMANENTLY
            );
        }

        $rawQuery = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a1')
            ->where('a1.parent_id = a.id')
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a1.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $sort_order = SORT_DESC; // SORT_ASC is also possible
        $urls = [];
        $parentIds = [];
        $rows = [];
        $contentIds = $this->tagRepository->findPublishedContentIds($tag->id, ContentType::PAGE);
        if ($contentIds !== []) {
            $ids = array_map(static fn(\Register\Content\ContentId $contentId): int => $contentId->value, $contentIds);
            $result = $this->dbLayer
                ->select('a.title, a.slug AS url, (' . $rawQuery . ') IS NOT NULL AS children_exist')
                ->addSelect('a.id, a.excerpt, a.featured AS favorite, a.published_at AS create_time, a.parent_id')
                ->from(ContentSchema::TABLE_NAME . ' AS a')
                ->where('a.id IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')')
                ->andWhere("a.content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('a.published = 1')
                ->execute($ids)
            ;
            while ($row = $result->fetchAssoc()) {
                $rows[]      = $row;
                $urls[]      = rawurlencode($row['url']);
                $parentIds[] = $row['parent_id'];
            }
        }

        $urls = $this->articleProvider->getFullUrlsForArticles($parentIds, $urls);
        $sections = [];
        $articles = [];
        $sortingValuesForArticles = [];
        $sortingValuesForSections = [];
        if (\count($urls) > 0) {
            $favoriteLink = $this->urlBuilder->link('/' . rawurlencode($this->favoriteUrl->get()) . '/');
            $useHierarchy = $this->useHierarchy->get();
            foreach ($urls as $k => $url) {
                $row  = $rows[$k];
                $item = [
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'link'          => $this->urlBuilder->link($url . ($useHierarchy && (bool)$row['children_exist'] ? '/' : '')),
                    'favorite_link' => $favoriteLink,
                    'date'          => $this->viewer->date($row['create_time']),
                    'excerpt'       => $row['excerpt'],
                    'favorite'      => $row['favorite'],
                ];
                if ((bool)$row['children_exist']) {
                    $sections[]                 = $item;
                    $sortingValuesForSections[] = $row['create_time'];
                } else {
                    $articles[]                 = $item;
                    $sortingValuesForArticles[] = $row['create_time'];
                }
            }
        }

        $sectionText = '';
        if (\count($sections) > 0) {
            // There are sections having the tag
            array_multisort($sortingValuesForSections, $sort_order, $sections);
            foreach ($sections as $item) {
                $sectionText .= $this->viewer->render('subarticles_item', $item);
            }
        }

        $articleText = '';
        if (\count($articles) > 0) {
            // There are articles having the tag
            array_multisort($sortingValuesForArticles, $sort_order, $articles);
            foreach ($articles as $item) {
                $articleText .= $this->viewer->render('subarticles_item', $item);
            }
        }

        $template = $this->htmlTemplateProvider->getTemplate('site.php');

        $template
            ->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'))
            ->addBreadCrumb($this->translator->trans('Tags'), $this->urlBuilder->link('/' . rawurlencode($this->tagsUrlFragment->get()) . '/'))
            ->addBreadCrumb($tagName)
            ->putInPlaceholder('title', $this->viewer->render('tag_title', ['title' => $tagName]))
            ->putInPlaceholder('date', '')
            ->putInPlaceholder('text', $this->viewer->render('list_text', [
                'description' => $tagDescription,
                'articles'    => $articleText,
                'sections'    => $sectionText,
            ]))
        ;

        return $template->toHttpResponse();
    }
}
