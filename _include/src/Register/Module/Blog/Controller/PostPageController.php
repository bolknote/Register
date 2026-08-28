<?php

declare(strict_types = 1);

/**
 * Single blog post.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Content\ContentRenderedEvent;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Live\LiveUpdateContext;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Rose\Entity\ExternalId;
use Register\Module\Search\Module as SearchModule;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\SearchDocumentFactory;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Cache\InvalidArgumentException;
use Register\Core\Pdo\DbLayerException;

class PostPageController extends BlogController
{
    public function __construct(
        DbLayer                                  $dbLayer,
        CalendarBuilder                          $calendarBuilder,
        BlogUrlBuilder                           $blogUrlBuilder,
        ArticleProvider                          $articleProvider,
        PostProvider                             $postProvider,
        ContentUrlGenerator                      $contentUrlGenerator,
        UrlBuilder                               $urlBuilder,
        private readonly ?RecommendationProvider $recommendationProvider,
        private readonly VisitorIdentityManager  $visitorIdentityManager,
        TranslatorInterface                      $translator,
        HtmlTemplateProvider                     $templateProvider,
        Viewer                                   $viewer,
        private readonly ContentCommentRenderer  $commentRenderer,
        private readonly LiveUpdateContext       $liveUpdates,
        private readonly PostInplaceControls      $inplaceControls,
        private readonly TagRepository            $tagRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        StringProxy                              $blogTitle,
        BoolProxy                                $showComments,
        BoolProxy                                $enabledComments,
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
            $enabledComments
        );
    }

    /**
     * @throws DbLayerException
     * @throws InvalidArgumentException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        $this->liveUpdates->start();
        $url = $request->attributes->getString('url');

        $template->putInPlaceholder('title', '');

        $result = $this->getPost($request, $template, $url);
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        return null;
    }

    /**
     * @throws InvalidArgumentException
     * @throws DbLayerException
     */
    private function getPost(Request $request, HtmlTemplate $template, string $url): ?Response
    {
        $template->setLink('up', $this->blogUrlBuilder->main());

        $result = $this->dbLayer
            ->select(
                'published_at AS create_time, date_label AS display_date, title, body AS text, id, author_id, revision, comments_enabled AS commented, series AS label, featured AS favorite, meta_description, social_image',
                '(' . $this->dbLayer
                    ->select('u.name')
                    ->from('users AS u')
                    ->where('u.id = p.author_id')
                    ->getSql() . ') AS author',
                'slug AS url'
            )
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('slug = :url')->setParameter('url', $url)
            ->andWhere('published = 1')
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row === false) {
            $notFoundTitle = $this->translator->trans('Not found');
            $template
                ->putInPlaceholder('head_title', $notFoundTitle)
                ->putInPlaceholder('title', register_htmlencode($notFoundTitle))
                ->putInPlaceholder('text', '')
            ;

            return $template->toHttpResponse()->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        $post_id = $row['id'];
        $label   = (string)$row['label'];

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $createTime = (int)$row['create_time'];
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar(
                (int)date('Y', $createTime),
                (int)date('m', $createTime),
                (int)date('d', $createTime),
                $url
            ));
        }

        $template->putInPlaceholder('canonical_path', $this->contentUrlGenerator->post((string)$row['url']));

        $is_back_forward = $template->hasPlaceholder('<!-- register_blog_back_forward -->');
        $queries = [];
        $params = [];
        if ($label !== '') {
            // Getting posts that have the same label
            $queries[]         = $this->dbLayer->select('title, published_at AS create_time, slug AS url, "label" AS type')
                ->from(ContentSchema::TABLE_NAME)
                ->where("content_type = '" . ContentType::POST->value . "'")
                ->andWhere('series = :label')
                ->andWhere('id <> :post_id')
                ->andWhere('published = 1')
                ->orderBy('published_at DESC')
                ->getSql()
            ;
            $params['label']   = $label;
            $params['post_id'] = $post_id;
        }

        if ($is_back_forward) {
            $queries[] = $this->dbLayer->select('title, published_at AS create_time, slug AS url, "next" AS type')
                ->from(ContentSchema::TABLE_NAME)
                ->where("content_type = '" . ContentType::POST->value . "'")
                ->andWhere('published_at > :time_next')
                ->andWhere('published = 1')
                ->orderBy('published_at ASC')
                ->limit(1)
                ->getSql()
            ;

            $params['time_next'] = (int)$row['create_time'];

            $queries[] = $this->dbLayer->select('title, published_at AS create_time, slug AS url, "prev" AS type')
                ->from(ContentSchema::TABLE_NAME)
                ->where("content_type = '" . ContentType::POST->value . "'")
                ->andWhere('published_at < :time_prev')
                ->setParameter('time_prev', (int)$row['create_time'], \PDO::PARAM_INT)
                ->andWhere('published = 1')
                ->orderBy('published_at DESC')
                ->limit(1)
                ->getSql()
            ;

            $params['time_prev'] = (int)$row['create_time'];
        }

        $result = $queries !== [] ? $this->dbLayer->query('(' . implode(') UNION (', $queries) . ')', $params) : null;

        $back_forward = [];
        while ($result instanceof \Register\Core\Pdo\QueryResult && ($row1 = $result->fetchAssoc()) !== false) {
            $post_info = [
                'title' => $row1['title'],
                'link'  => $this->contentUrlGenerator->post((string)$row1['url']),
            ];

            if ($row1['type'] === 'label') {
                $row['see_also'][] = $post_info;
            } elseif ($row1['type'] === 'next') {
                $template->setLink('next', $post_info['link']);
                $back_forward['forward'] = $post_info;
            } elseif ($row1['type'] === 'prev') {
                $template->setLink('prev', $post_info['link']);
                $back_forward['back'] = $post_info;
            }
        }

        if ($back_forward !== []) {
            $template->registerPlaceholder('<!-- register_blog_back_forward -->', $this->viewer->render('back_forward_post', $back_forward, BlogModule::class));
        }

        // Getting tags
        $tags = [];
        $tagsByContent = $this->tagRepository->findForContent([ContentId::post((int)$post_id)]);
        foreach ($tagsByContent['post:' . $post_id] as $tag) {
            $tags[] = [
                'title' => $tag->name,
                'link'  => $this->blogUrlBuilder->tag($tag->slug),
            ];
        }

        $contentId = ContentId::post((int)$post_id);
        $request->attributes->set(FlatContentController::CONTENT_ID_ATTRIBUTE, $contentId);
        $isSharedResponse = $request->attributes->getBoolean(FlatContentController::SHARED_RESPONSE_ATTRIBUTE);
        $template->putInPlaceholder('commented', $isSharedResponse ? 0 : $row['commented']);
        if ((bool)$row['commented'] && $this->showComments->get() && $template->hasPlaceholder('<!-- register_comments -->')) {
            $this->liveUpdates->subscribeComments($contentId);
            $template->putInPlaceholder(
                'comments',
                $this->commentRenderer->renderRegion($contentId, $request, $request->getPathInfo()),
            );
        }

        $row['time']             = $this->postProvider->displayDate((int)$row['create_time'], (string)$row['display_date']);
        $row['commented']        = 0; // for template
        $row['tags']             = $tags;
        $row['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
        $row['showComments']     = $this->showComments->get();
        $row['enabledComments']  = $this->enabledComments->get();
        if (!$this->postProvider->hasMultiplePublishedAuthors()) {
            $row['author'] = '';
        }

        $row['inplace']          = $this->inplaceControls->forPost(
            $request,
            (int)$post_id,
            $row['author_id'] === null ? null : (int)$row['author_id'],
            (int)$row['revision'],
        );

        $template
            ->putInPlaceholder('meta_description', trim((string)$row['meta_description']) !== ''
                ? (string)$row['meta_description']
                : $this->extractMetaDescriptions($row['text']))
            ->putInPlaceholder('social_image', (string)$row['social_image'])
            ->putInPlaceholder('social_type', 'article')
            ->putInPlaceholder('text', $this->viewer->render('post', $row, BlogModule::class))
            ->putInPlaceholder('id', md5('register_blog_post_' . $post_id))
            ->putInPlaceholder('head_title', register_htmlencode($row['title']))
        ;

        if ($this->recommendationProvider instanceof RecommendationProvider && $template->hasPlaceholder('<!-- register_recommendations -->')) {
            $request_uri = $request->getPathInfo();
            [$recommendations, $log, $rawRecommendations] = $this->recommendationProvider->getRecommendations(
                $request_uri,
                new ExternalId(SearchDocumentFactory::externalId(ContentId::post((int)$post_id))),
                $this->visitorIdentityManager->visitorIdFromRequest($request) !== null,
            );
            $template->putInPlaceholder('recommendations', $this->viewer->render('recommendations', [
                'raw'     => $rawRecommendations,
                'content' => $recommendations,
                'log'     => $log,
            ], SearchModule::class));
        }

        $this->eventDispatcher->dispatch(new ContentRenderedEvent($template, $contentId));

        return null;
    }

    private function extractMetaDescriptions(string $text): string
    {
        $replace_what = ["\r", '&nbsp;', '&mdash;', '&ndash;', '&laquo;', '&raquo;'];
        $replace_to   = ['', ' ', '—', '–', '«', '»',];
        foreach (['<br>', '<br />', '<h1>', '<h2>', '<h3>', '<h4>', '<p>', '<pre>', '<blockquote>', '<li>'] as $tag) {
            $replace_what[] = $tag;
            $replace_to[]   = $tag . "\r";
        }

        $text = str_replace($replace_what, $replace_to, $text);
        $text = strip_tags($text);

        $normalizedText = preg_replace('#(?<=[.?!;])[ \n\t]+#S', "\r", $text);
        if ($normalizedText === null) {
            throw new \RuntimeException('Unable to normalize the blog post description.');
        }

        $text = $normalizedText;
        $text = trim($text);

        $start = 0;
        while (($pos = mb_strpos($text, "\r", $start)) !== false) {
            if ($pos > 160 && $start <= 160) {
                $text = mb_substr($text, 0, $start);
                break;
            }

            $start = $pos + 1;
        }

        return str_replace("\r", ' ', $text);
    }
}
