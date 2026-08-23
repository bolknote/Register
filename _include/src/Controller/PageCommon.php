<?php
/**
 * Displays a page stored in DB.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Content\ContentRenderedEvent;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Live\LiveUpdateContext;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Framework\Exception\ConfigurationException;
use Register\Core\Framework\Exception\NotFoundException;
use Register\Core\Helper\StringHelper;
use Register\Core\Model\Article\ArticleRenderedEvent;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;


readonly class PageCommon implements ControllerInterface
{
    public function __construct(
        private DbLayer                  $dbLayer,
        private TagRepository            $tagRepository,
        private ArticleProvider          $articleProvider,
        private EventDispatcherInterface $eventDispatcher,
        private UrlBuilder               $urlBuilder,
        private TranslatorInterface      $translator,
        private HtmlTemplateProvider     $htmlTemplateProvider,
        private Viewer                   $viewer,
        private ContentCommentRenderer   $commentRenderer,
        private LiveUpdateContext        $liveUpdates,
        private BoolProxy                $useHierarchy,
        private BoolProxy                $showComments,
        private StringProxy              $tagsUrl,
        private StringProxy              $favoriteUrl,
        private IntProxy                 $maxItems,
        private bool                     $debug,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws NotFoundException
     * @throws ConfigurationException
     * @throws BadRequestException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        $this->liveUpdates->start();
        $request_uri = $request->getPathInfo();

        $request_array = explode('/', $request_uri);   //   []/[dir1]/[dir2]/[dir3]/[file1]
        $request_array = array_map(rawurldecode(...), $request_array);

        $useHierarchy = $this->useHierarchy->get();
        $showComments = $this->showComments->get();
        $favoriteUrl  = $this->favoriteUrl->get();
        $maxItems     = $this->maxItems->get();

        // Correcting trailing slash and the rest of URL
        if (!$useHierarchy && \count($request_array) > 2) {
            return new RedirectResponse($this->urlBuilder->link('/' . $request_array[1]), Response::HTTP_MOVED_PERMANENTLY);
        }

        $was_end_slash = str_ends_with($request_uri, '/');

        $bread_crumbs = [];

        $parent_path = '';
        $parent_id   = ArticleProvider::ROOT_ID;
        $parent_num  = \count($request_array) - 1 - (int)$was_end_slash;

        $template_id = '';
        $i           = 0;

        if ($useHierarchy) {
            $urls = array_unique($request_array);

            $result = $this->dbLayer->select('id, parent_id, slug, title, template')
                ->from(ContentSchema::TABLE_NAME)
                ->where('slug IN (' . implode(',', array_fill(0, \count($urls), '?')) . ')')
                ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('published=1')
                ->execute($urls)
            ;

            $nodes = $result->fetchAssocAll();

            /**
             * Walking through the page parents
             * 1. We ensure all of them are published
             * 2. We build "bread crumbs"
             * 3. We determine the template of the page
             */
            for (; $i < $parent_num; ++$i) {
                $parent_path .= rawurlencode($request_array[$i]) . '/';

                $cur_node       = [];
                $found_node_num = 0;
                foreach ($nodes as $node) {
                    $nodeParentId = $node['parent_id'] === null
                        ? ArticleProvider::ROOT_ID
                        : (int)$node['parent_id'];
                    if ($nodeParentId === $parent_id && $node['slug'] === $request_array[$i]) {
                        $cur_node = $node;
                        ++$found_node_num;
                    }
                }

                if ($found_node_num === 0) {
                    throw new NotFoundException();
                }

                if ($found_node_num > 1) {
                    throw new ConfigurationException(
                        $this->translator->trans('DB repeat items') . ($this->debug ? ' (parent_id=' . $parent_id . ', url="' . register_htmlencode($request_array[$i]) . '")' : ''),
                        $this->translator->trans('Error encountered')
                    );
                }

                $parent_id = (int)$cur_node['id'];
                if ($cur_node['template'] !== '') {
                    $template_id = $cur_node['template'];
                }

                $bread_crumbs[] = [
                    'link'  => $this->urlBuilder->link($parent_path),
                    'title' => $cur_node['title']
                ];
            }
        } else {
            $parent_path = '/';
            $i           = 1;
        }

        // Path to the requested page (without trailing slash)
        $requestedPage = $request_array[$i] ?? throw new BadRequestException('The requested page path is incomplete.');
        $current_path  = $parent_path . rawurlencode($requestedPage);

        $raw_query_children = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a1')
            ->where('a1.parent_id = a.id')
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a1.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $raw_query_author = $this->dbLayer
            ->select('u.name')
            ->from('users AS u')
            ->where('u.id = a.author_id')
            ->getSql()
        ;

        $qb = $this->dbLayer
            ->select('a.id, a.title, a.meta_keywords, a.meta_description, a.social_image')
            ->addSelect('a.excerpt, a.body AS text, a.published_at AS date')
            ->addSelect('a.featured AS favorite, a.comments_enabled AS commented, a.template')
            ->addSelect('(' . $raw_query_children . ') IS NOT NULL AS children_exist, (' . $raw_query_author . ') AS author')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.slug = :url')->setParameter('url', $requestedPage)
            ->andWhere('a.published = 1')
        ;
        if ($useHierarchy) {
            if ($parent_id === ArticleProvider::ROOT_ID) {
                $qb->andWhere('a.parent_id IS NULL');
            } else {
                $qb->andWhere('a.parent_id = :parent_id')->setParameter('parent_id', $parent_id);
            }
        }

        $result = $qb->execute();
        $page   = $result->fetchAssoc();

        // Error handling
        if ($page === false) {
            throw new NotFoundException();
        }

        if ($result->fetchAssoc() !== false) {
            throw new ConfigurationException(
                $this->translator->trans('DB repeat items') . ($this->debug ? ' (parent_id=' . $parent_id . ', url="' . register_htmlencode($requestedPage) . '")' : ''),
                $this->translator->trans('Error encountered')
            );
        }

        if ((string)$page['template'] !== '') {
            $template_id = (string)$page['template'];
        }

        if ($template_id === '') {
            if ($useHierarchy) {
                $bread_crumbs[] = [
                    'link'  => $this->urlBuilder->link($parent_path),
                    'title' => $page['title'],
                ];

                $errorMessage = \sprintf($this->translator->trans('Error no template'), implode('<br />', array_map(static fn(array $a): string => '<a href="' . $a['link'] . '">' . register_htmlencode($a['title']) . '</a>', $bread_crumbs)));
            } else {
                $errorMessage = $this->translator->trans('Error no template flat');
            }

            throw new ConfigurationException(
                $errorMessage,
                $this->translator->trans('Error encountered')
            );
        }

        if ($useHierarchy && $parent_num > 0 && $was_end_slash !== (bool)$page['children_exist']) {
            return new RedirectResponse($this->urlBuilder->link($current_path . (!$was_end_slash ? '/' : '')), Response::HTTP_MOVED_PERMANENTLY);
        }

        $articleId = (int)$page['id'];
        $template  = $this->htmlTemplateProvider->getTemplate($template_id);
        $template
            ->putInPlaceholder('id', md5('article_' . $articleId)) // for comments form
            ->putInPlaceholder('meta_keywords', $page['meta_keywords'])
            ->putInPlaceholder('meta_description', $page['meta_description'])
            ->putInPlaceholder('social_image', $page['social_image'])
            ->putInPlaceholder('social_type', 'article')
            ->putInPlaceholder('excerpt', $page['excerpt'])
            ->putInPlaceholder('text', $page['text'])
            ->putInPlaceholder('date', $page['date'])
            ->putInPlaceholder('favorite', $page['favorite'])
            ->putInPlaceholder('commented', $page['commented'])
            ->putInPlaceholder('author', $page['author'])
            ->putInPlaceholder('canonical_path', $current_path . ($was_end_slash ? '/' : ''))
        ;

        if ($page['favorite'] === 1) {
            $template->putInPlaceholder('favorite_link', $this->urlBuilder->link('/' . rawurlencode($favoriteUrl) . '/'));
        }

        $bread_crumbs[] = [
            'title' => $page['title']
        ];
        $template->putInPlaceholder('title', register_htmlencode($page['title']));

        if ($page['author'] !== null && $page['author'] !== '') {
            $template->putInPlaceholder('author', register_htmlencode($page['author']));
        }

        if ($useHierarchy) {
            foreach ($bread_crumbs as $crumb) {
                $template->addBreadCrumb($crumb['title'], $crumb['link'] ?? null);
            }

            $template->setLink('top', $this->urlBuilder->link('/'));

            if (\count($bread_crumbs) > 1) {
                $template->setLink('up', $this->urlBuilder->link($parent_path));
                $template->putInPlaceholder(
                    'section_link',
                    '<a href="' . $this->urlBuilder->link($parent_path) . '">' . $bread_crumbs[\count($bread_crumbs) - 2]['title'] . '</a>'
                );
            }
        }

        // Dealing with sections, subsections, neighbours
        if (
            $useHierarchy
            && (bool)$page['children_exist']
            && (
                $template->hasPlaceholder('<!-- register_subarticles -->')
                || $template->hasPlaceholder('<!-- register_menu_children -->')
                || $template->hasPlaceholder('<!-- register_menu_subsections -->')
                || $template->hasPlaceholder('<!-- register_navigation_link -->')
            )
        ) {
            // It's a section. We have to fetch subsections and articles.

            // Fetching children
            $raw_query1 = $this->dbLayer
                ->select('a1.id')
                ->from(ContentSchema::TABLE_NAME . ' AS a1')
                ->where('a1.parent_id = a.id')
                ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('a1.published = 1')
                ->limit(1)
                ->getSql()
            ;

            $result = $this->dbLayer
                ->select('title, slug AS url, (' . $raw_query1 . ') IS NOT NULL AS children_exist')
                ->addSelect('id, excerpt, featured AS favorite, published_at AS create_time, parent_id')
                ->from(ContentSchema::TABLE_NAME . ' AS a')
                ->where("content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('parent_id = :parent_id')->setParameter('parent_id', $articleId)
                ->andWhere('published = 1')
                ->orderBy('sort_order')
                ->execute()
            ;
            $subarticles = [];
            $subsections = [];
            $sort_array = [];
            while (($row = $result->fetchAssoc()) !== false) {
                if ((bool)$row['children_exist']) {
                    // The child is a subsection
                    $item = [
                        'id'            => $row['id'],
                        'title'         => $row['title'],
                        'link'          => $this->urlBuilder->link($current_path . '/' . rawurlencode($row['url']) . '/'),
                        'favorite_link' => $this->urlBuilder->link('/' . rawurlencode($favoriteUrl) . '/'),
                        'date'          => $this->viewer->date($row['create_time']),
                        'excerpt'       => $row['excerpt'],
                        'favorite'      => $row['favorite'],
                    ];

                    $subsections[] = $item;
                } else {
                    // The child is an article
                    $item       = [
                        'id'            => $row['id'],
                        'title'         => $row['title'],
                        'link'          => $this->urlBuilder->link($current_path . '/' . rawurlencode($row['url'])),
                        'favorite_link' => $this->urlBuilder->link('/' . rawurlencode($favoriteUrl) . '/'),
                        'date'          => $this->viewer->date($row['create_time']),
                        'excerpt'       => $row['excerpt'],
                        'favorite'      => $row['favorite'],
                    ];
                    $sort_field = $row['create_time'];

                    $subarticles[] = $item;
                    $sort_array[]  = $sort_field;
                }
            }

            $sections_text = '';
            if (\count($subsections) > 0) {
                // There are subsections in the section
                $template->putInPlaceholder('menu_subsections', $this->viewer->render('menu_block', [
                    'title' => $this->translator->trans('Subsections'),
                    'menu'  => $subsections,
                    'class' => 'menu_subsections',
                ]));

                foreach ($subsections as $subsection) {
                    $sections_text .= $this->viewer->render('subarticles_item', $subsection);
                }
            }

            $articles_text = '';
            if (\count($subarticles) > 0) {
                // There are articles in the section
                $template->putInPlaceholder('menu_children', $this->viewer->render('menu_block', [
                    'title' => $this->translator->trans('In this section'),
                    'menu'  => $subarticles,
                    'class' => 'menu_children',
                ]));

                arsort($sort_array);

                $paging = '';

                if ($maxItems > 0) {
                    // Paging navigation
                    $page_num = max(0, $request->query->getInt('p', 1) - 1);

                    $start = $page_num * $maxItems;
                    if ($start >= \count($subarticles)) {
                        $page_num = 0;
                        $start = 0;
                    }

                    $total_pages = intdiv(\count($subarticles) + $maxItems - 1, $maxItems);

                    $link_nav = [];
                    $paging   = StringHelper::paging($page_num + 1, $total_pages, $this->urlBuilder->link(str_replace('%', '%%', $current_path . '/'), ['p=%d']), $link_nav) . "\n";
                    foreach ($link_nav as $rel => $href) {
                        $template->setLink($rel, $href);
                    }

                    $sort_array = \array_slice($sort_array, $start, $maxItems, true);
                }

                foreach (array_keys($sort_array) as $index) {
                    $articles_text .= $this->viewer->render('subarticles_item', $subarticles[$index]);
                }

                if ($maxItems > 0) {
                    $articles_text .= $paging;
                }
            }

            $template->putInPlaceholder('subcontent', $this->viewer->render('subarticles', [
                'articles' => $articles_text,
                'sections' => $sections_text,
            ]));
        }

        if (
            $useHierarchy
            && !(bool)$page['children_exist']
            && (
                $template->hasPlaceholder('<!-- register_menu_siblings -->')
                || $template->hasPlaceholder('<!-- register_back_forward -->')
                || $template->hasPlaceholder('<!-- register_navigation_link -->')
            )
        ) {
            // It's an article. We have to fetch other articles in the parent section

            // Fetching "siblings"
            $raw_query_child_num = $this->dbLayer
                ->select('1')
                ->from(ContentSchema::TABLE_NAME . ' AS a2')
                ->where('a2.parent_id = a.id')
                ->andWhere("a2.content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('a2.published = 1')
                ->limit(1)
                ->getSql()
            ;

            $result = $this->dbLayer
                ->select('title, slug AS url, id, excerpt, published_at AS create_time, parent_id')
                ->from(ContentSchema::TABLE_NAME . ' AS a')
                ->where("content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('parent_id = :parent_id')->setParameter('parent_id', $parent_id)
                ->andWhere('published = 1')
                ->andWhere('(' . $raw_query_child_num . ') IS NULL')
                ->orderBy('sort_order')
                ->execute()
            ;
            $neighbour_urls = [];
            $menu_articles = [];

            $i         = 0;
            $curr_item = -1;
            while (($row = $result->fetchAssoc()) !== false) {
                // A neighbor
                $url = $this->urlBuilder->link($parent_path . rawurlencode($row['url']));

                $menu_articles[] = [
                    'title'      => $row['title'],
                    'link'       => $url,
                    'is_current' => $articleId === $row['id'],
                ];

                if ($articleId === $row['id']) {
                    $curr_item = $i;
                }

                $neighbour_urls[] = $url;

                ++$i;
            }

            if (\count($bread_crumbs) > 1) {
                $template->putInPlaceholder('menu_siblings', $this->viewer->render('menu_block', [
                    'title' => \sprintf($this->translator->trans('More in this section'), '<a href="' . $this->urlBuilder->link($parent_path) . '">' . $bread_crumbs[\count($bread_crumbs) - 2]['title'] . '</a>'),
                    'menu'  => $menu_articles,
                    'class' => 'menu_siblings',
                ]));
            }

            if ($curr_item !== -1) {
                $previousIndex = $curr_item - 1;
                if ($previousIndex >= 0 && isset($neighbour_urls[$previousIndex])) {
                    $template->setLink('prev', $neighbour_urls[$previousIndex]);
                }

                if (isset($neighbour_urls[$curr_item + 1])) {
                    $template->setLink('next', $neighbour_urls[$curr_item + 1]);
                }

                $previousArticle = $previousIndex >= 0 ? ($menu_articles[$previousIndex] ?? null) : null;
                $nextArticle = $menu_articles[$curr_item + 1] ?? null;
                $template->putInPlaceholder('back_forward', [
                    'up'      => \count($bread_crumbs) <= 1 ? null : [
                        'title' => $bread_crumbs[\count($bread_crumbs) - 2]['title'],
                        'link'  => $this->urlBuilder->link($parent_path),
                    ],
                    'back'    => $previousArticle === null ? null : [
                        'title' => $previousArticle['title'],
                        'link'  => $previousArticle['link'],
                    ],
                    'forward' => $nextArticle === null ? null : [
                        'title' => $nextArticle['title'],
                        'link'  => $nextArticle['link'],
                    ],
                ]);
            }
        }

        // Tags
        if ($template->hasPlaceholder('<!-- register_article_tags -->')) {
            $template->putInPlaceholder('article_tags', $this->tagged_articles($articleId));
        }

        if ($template->hasPlaceholder('<!-- register_tags -->')) {
            $template->putInPlaceholder('tags', $this->get_tags($articleId));
        }

        // Comments
        if ((bool)$page['commented'] && $showComments && $template->hasPlaceholder('<!-- register_comments -->')) {
            $contentId = ContentId::page($articleId);
            $this->liveUpdates->subscribeComments($contentId);
            $template->putInPlaceholder(
                'comments',
                $this->commentRenderer->renderRegion($contentId, $request, $request->getPathInfo()),
            );
        }

        $this->eventDispatcher->dispatch(new ArticleRenderedEvent($template, $articleId));
        $this->eventDispatcher->dispatch(new ContentRenderedEvent($template, ContentId::page($articleId)));

        return $template->toHttpResponse();
    }

    /**
     * @throws DbLayerException
     */
    private function tagged_articles(int $articleId): string
    {
        $tag_names = [];
        $tag_urls = [];
        $tagsByContent = $this->tagRepository->findForContent([ContentId::page($articleId)]);
        foreach ($tagsByContent['page:' . $articleId] as $tag) {
            $tag_names[$tag->id] = $tag->name;
            $tag_urls[$tag->id]  = $tag->slug;
        }

        if (\count($tag_urls) === 0) {
            return '';
        }

        $raw_query1 = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a1')
            ->where('a1.parent_id = atg.content_id')
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a1.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('title, tag_id, parent_id, slug AS url, a.id AS id, (' . $raw_query1 . ') IS NOT NULL AS children_exist')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->innerJoin(ContentTagSchema::TABLE_NAME . ' AS atg', 'atg.content_id = a.id')
            ->where("atg.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('atg.tag_id IN (' . implode(', ', array_fill(0, \count($tag_names), '?')) . ')')
            ->andWhere('a.published = 1')
            // ->orderBy('create_time') // no temp table is created but order by ID is almost the same
            ->execute(array_keys($tag_names))
        ;

        // Build article lists that have the same tags as our article

        $hasArticlesInList = false;
        $titles = [];
        $parent_ids = [];
        $urls = [];
        $tag_ids = [];
        $original_ids = [];
        while (($row = $result->fetchAssoc()) !== false) {
            if ($articleId !== (int)$row['id']) {
                $hasArticlesInList = true;
            }

            $titles[]       = $row['title'];
            $parent_ids[]   = $row['parent_id'];
            $urls[]         = rawurlencode($row['url']) . ($this->useHierarchy->get() && (bool)$row['children_exist'] ? '/' : '');
            $tag_ids[]      = $row['tag_id'];
            $original_ids[] = $row['id'];
        }

        if (\count($urls) === 0) {
            return '';
        }

        if ($hasArticlesInList) {
            $urls = $this->articleProvider->getFullUrlsForArticles($parent_ids, $urls);
        }

        // Sorting all obtained article links into groups by each tag
        $art_by_tags = [];

        foreach ($urls as $k => $url) {
            $art_by_tags[$tag_ids[$k]][] = [
                'title'      => $titles[$k],
                'link'       => $url,
                'is_current' => $original_ids[$k] === $articleId,
            ];
        }

        // Remove tags that have only one article
        foreach ($art_by_tags as $tag_id => $title_array) {
            if (\count($title_array) <= 1) {
                unset($art_by_tags[$tag_id]);
            }
        }

        $output = [];
        foreach ($art_by_tags as $tag_id => $articles) {
            $output[] = $this->viewer->render('menu_block', [
                'title' => \sprintf($this->translator->trans('With this tag'), '<a href="' . $this->urlBuilder->link('/' . rawurlencode($this->tagsUrl->get()) . '/' . rawurlencode($tag_urls[$tag_id]) . '/') . '">' . $tag_names[$tag_id] . '</a>'),
                'menu'  => $articles,
                'class' => 'article_tags',
            ]);
        }

        return implode("\n", $output);
    }

    /**
     * @throws DbLayerException
     */
    private function get_tags(int $articleId): string
    {
        $tagsUrl = $this->tagsUrl->get();

        $tags = [];
        $tagsByContent = $this->tagRepository->findForContent([ContentId::page($articleId)]);
        foreach ($tagsByContent['page:' . $articleId] as $tag) {
            $tags[] = [
                'title' => $tag->name,
                'link'  => $this->urlBuilder->link('/' . rawurlencode($tagsUrl) . '/' . rawurlencode($tag->slug) . '/'),
            ];
        }

        if (\count($tags) === 0) {
            return '';
        }

        return $this->viewer->render('tags', [
            'title' => $this->translator->trans('Tags'),
            'tags'  => $tags,
        ]);
    }
}
