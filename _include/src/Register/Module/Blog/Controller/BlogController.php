<?php

declare(strict_types = 1);

/**
 * General blog page.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

abstract class BlogController implements ControllerInterface
{
    protected string $template_id = 'blog.php';

    public function __construct(
        protected DbLayer              $dbLayer,
        protected CalendarBuilder      $calendarBuilder,
        protected BlogUrlBuilder       $blogUrlBuilder,
        protected ArticleProvider      $articleProvider,
        protected PostProvider         $postProvider,
        protected ContentUrlGenerator  $contentUrlGenerator,
        protected UrlBuilder           $urlBuilder,
        protected TranslatorInterface  $translator,
        protected HtmlTemplateProvider $templateProvider,
        protected Viewer               $viewer,
        protected StringProxy          $blogTitle,
        protected BoolProxy            $showComments,
        protected BoolProxy            $enabledComments,
    ) {
    }

    abstract public function body(Request $request, HtmlTemplate $template): ?Response;

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        $template = $this->templateProvider->getTemplate($this->template_id, BlogModule::class);

        /** @noinspection HtmlUnknownTarget */
        $template
            ->putInPlaceholder('commented', 0)
            ->putInPlaceholder('class', 'register_blog')
            ->putInPlaceholder('rss_link', [
                \sprintf(
                    '<link rel="alternate" type="application/rss+xml" title="%s" href="%s" />',
                    register_htmlencode($this->translator->trans('RSS blog link title')),
                    $this->blogUrlBuilder->main() . 'rss',
                ),
                \sprintf(
                    '<link rel="alternate" type="application/feed+json" title="%s" href="%s" />',
                    register_htmlencode($this->translator->trans('JSON blog link title')),
                    $this->blogUrlBuilder->jsonFeed(),
                ),
            ])
        ;

        $result = $this->body($request, $template);
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        $headTitle = $template->getFromPlaceholder('head_title');
        $blogTitle = $this->blogTitle->get();
        $template->putInPlaceholder('head_title', $headTitle === null ? $blogTitle : $headTitle . ' &mdash; ' . $blogTitle);

        return $template->toHttpResponse();
    }

    /**
     * @param list<int>|null $idOrder
     * @throws DbLayerException
     */
    public function getPosts(
        callable $queryModifier,
        bool $sortAsc = true,
        string $sortField = 'create_time',
        ?array $idOrder = null,
    ): string
    {
        // Obtaining posts
        $qb = $this->dbLayer
            ->select(
                'p.published_at AS create_time', 'p.date_label AS display_date', 'p.title', 'p.body AS text',
                'p.slug AS url', 'p.id', 'p.comments_enabled AS commented', 'p.featured AS favorite',
                '(' . $this->dbLayer
                    ->select('count(*)')
                    ->from(CommentSchema::TABLE_NAME . ' AS c')
                    ->where("c.content_type = '" . ContentType::POST->value . "'")
                    ->andWhere('c.content_id = p.id')
                    ->andWhere('c.shown = 1')
                    ->getSql() . ') AS comment_num',
                '(' . $this->dbLayer
                    ->select('u.name')
                    ->from('users AS u')
                    ->where('u.id = p.author_id')
                    ->getSql() . ') AS author',
                'p.series AS label'
            )
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('p.content_type = :post_content_type')
            ->setParameter('post_content_type', ContentType::POST->value)
            ->andWhere('p.published = 1')
        ;

        $queryModifier($qb);

        $result = $qb->execute();
        $posts = [];
        $merge_labels = [];
        $labels = [];
        $ids = [];
        $sort_array = [];
        while ($row = $result->fetchAssoc()) {
            $posts[$row['id']]  = $row;
            $ids[]              = $row['id'];
            if ($idOrder === null) {
                $sort_array[] = $row[$sortField];
            } else {
                $orderPosition = array_search((int)$row['id'], $idOrder, true);
                $sort_array[] = $orderPosition === false ? PHP_INT_MAX : $orderPosition;
            }

            $labels[$row['id']] = $row['label'];
            if ((string)$row['label'] !== '') {
                $merge_labels[$row['label']] = 1;
            }
        }

        if (\count($posts) === 0) {
            return '';
        }

        $see_also = [];
        $tags = [];
        $this->postProvider->postsLinks($ids, $merge_labels, $see_also, $tags);

        array_multisort($sort_array, $idOrder !== null || $sortAsc ? SORT_ASC : SORT_DESC, $ids);

        $showComments    = $this->showComments->get();
        $enabledComments = $this->enabledComments->get();
        $showAuthors     = $this->postProvider->hasMultiplePublishedAuthors();
        $output = '';
        foreach ($ids as $id) {
            $post               = &$posts[$id];
            $link               = $this->contentUrlGenerator->post((string)$post['url']);
            $post['link']       = $link;
            $post['title_link'] = $link;
            $post['time']       = $this->postProvider->displayDate((int)$post['create_time'], (string)$post['display_date']);
            $post['tags']       = $tags[$id] ?? [];

            $post['see_also'] = [];
            if (isset($labels[$id]) && (string)$labels[$id] !== '' && isset($see_also[$labels[$id]])) {
                $label_copy = $see_also[$labels[$id]];
                if (isset($label_copy[$id])) {
                    unset($label_copy[$id]);
                }

                $post['see_also'] = $label_copy;
            }

            $post['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
            $post['showComments']     = $showComments;
            $post['enabledComments']  = $enabledComments;
            if (!$showAuthors) {
                $post['author'] = '';
            }

            $output .= $this->viewer->render('post', $post, BlogModule::class);
        }

        return $output;
    }
}
