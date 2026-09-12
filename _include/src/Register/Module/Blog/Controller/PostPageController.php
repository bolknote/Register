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
use Register\Comment\CommentAgePolicy;
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
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\DeferredPostPageContext;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\DeferredRecommendations;
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
        private readonly CommentAgePolicy        $commentAgePolicy,
        private readonly BlogPageCache           $pageCache,
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
        $editor = $this->inplaceControls->editorForCreate($request);
        $now = time();
        $publicVisibility = 'published = 1 AND published_at IS NOT NULL AND published_at <= :post_visible_at';
        $visibility = $publicVisibility;
        if ($editor !== null) {
            $ownerVisibility = $editor->canEditSite ? '1 = 1' : 'author_id = :post_editor_id';
            $visibility = '(' . $publicVisibility . ') OR ((' . $ownerVisibility . ') AND ('
                . 'published = 0'
                . ' OR (published = 1 AND published_at > :post_visible_at)'
                . '))';
        }

        $query = $this->dbLayer
            ->select(
                'published_at AS create_time, created_at, scheduled_at, published, date_label AS display_date, title, body AS text, id, author_id, revision, comments_enabled AS commented, series AS label, featured AS favorite, meta_description, social_image',
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
            ->andWhere('(' . $visibility . ')')
            ->setParameter('post_visible_at', $now)
        ;
        if ($editor !== null && !$editor->canEditSite) {
            $query->setParameter('post_editor_id', $editor->id);
        }

        $result = $query->execute();

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

        $post_id = (int)$row['id'];
        $contentId = ContentId::post($post_id);
        $scheduledAt = (int)$row['scheduled_at'];
        $isScheduledPreview = ((int)$row['published'] === 0 && $scheduledAt > 0)
            || ((int)$row['published'] === 1 && (int)$row['create_time'] > $now);
        $isDraftPreview = (int)$row['published'] === 0 && $scheduledAt <= 0;
        $isPrivatePreview = $isScheduledPreview || $isDraftPreview;
        if ($isScheduledPreview) {
            $row['create_time'] = $scheduledAt > 0 ? $scheduledAt : (int)$row['create_time'];
            $row['scheduled_preview'] = true;
        }

        if ($isDraftPreview) {
            $row['create_time'] = max(1, (int)($row['create_time'] ?? $row['created_at']));
            $row['draft_preview'] = true;
        }

        if ($isPrivatePreview) {
            $template->addMetaTag('<meta name="robots" content="noindex, nofollow" />');
        }

        if (!$isPrivatePreview && $template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder(
                '<!-- register_blog_calendar -->',
                DeferredPostPageContext::placeholder(DeferredPostPageContext::CALENDAR, $post_id),
            );
        }

        $template->putInPlaceholder('canonical_path', $this->contentUrlGenerator->post((string)$row['url']));

        if (!$isPrivatePreview && $template->hasPlaceholder('<!-- register_blog_back_forward -->')) {
            $template->registerPlaceholder(
                '<!-- register_blog_back_forward -->',
                DeferredPostPageContext::placeholder(DeferredPostPageContext::BACK_FORWARD, $post_id),
            );
            $template->addMetaTag(
                DeferredPostPageContext::placeholder(DeferredPostPageContext::HEAD_LINKS, $post_id),
            );
        }

        // Getting tags
        $tags = [];
        $tagsByContent = $this->tagRepository->findForContent([ContentId::post($post_id)]);
        foreach ($tagsByContent['post:' . $post_id] as $tag) {
            $tags[] = [
                'title' => $tag->name,
                'link'  => $this->blogUrlBuilder->tag($tag->slug),
            ];
        }

        if (!$isPrivatePreview) {
            $request->attributes->set(FlatContentController::CONTENT_ID_ATTRIBUTE, $contentId);
        }

        $isSharedResponse = $request->attributes->getBoolean(FlatContentController::SHARED_RESPONSE_ATTRIBUTE);
        $commentsClosedByAge = !$isPrivatePreview && $this->commentAgePolicy->isClosed((int)$row['create_time'], $now);
        if (!$isPrivatePreview && (bool)$row['commented'] && !$commentsClosedByAge) {
            $closesAt = $this->commentAgePolicy->closesAt((int)$row['create_time']);
            if ($closesAt !== null) {
                $this->pageCache->invalidateCurrentResponseAt($closesAt);
            }
        }

        $template->putInPlaceholder('commented', $isSharedResponse || $isPrivatePreview || $commentsClosedByAge ? 0 : $row['commented']);
        if (!$isPrivatePreview && (bool)$row['commented'] && $this->showComments->get() && $template->hasPlaceholder('<!-- register_comments -->')) {
            $this->liveUpdates->subscribeComments($contentId);
            $template->putInPlaceholder(
                'comments',
                $this->commentRenderer->renderRegion($contentId, $request, $request->getPathInfo())
                    . ($commentsClosedByAge
                        ? '<p class="comment-discussion-closed">' . register_htmlencode($this->translator->trans('Comments closed by age')) . '</p>'
                        : ''),
            );
        }

        $row['time']             = $this->postProvider->displayDate((int)$row['create_time'], (string)$row['display_date']);
        $row['commented']        = 0; // for template
        $row['tags']             = $tags;
        $row['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
        $row['showComments']     = $this->showComments->get();
        $row['enabledComments']  = $this->enabledComments->get();
        if ($isPrivatePreview) {
            $row['deferred_author'] = null;
        } else {
            $row['author'] = '';
            $row['deferred_author'] = DeferredPostPageContext::placeholder(
                DeferredPostPageContext::AUTHOR,
                $post_id,
            );
        }

        $row['see_also'] = [];
        $row['deferred_see_also'] = $isPrivatePreview
            ? null
            : DeferredPostPageContext::placeholder(DeferredPostPageContext::SEE_ALSO, $post_id);

        $row['inplace']          = $this->inplaceControls->forPost(
            $request,
            $post_id,
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

        if (!$isPrivatePreview && $this->recommendationProvider instanceof RecommendationProvider && $template->hasPlaceholder('<!-- register_recommendations -->')) {
            $template->putInPlaceholder('recommendations', DeferredRecommendations::placeholder($contentId));
        }

        if (!$isPrivatePreview) {
            $this->eventDispatcher->dispatch(new ContentRenderedEvent($template, $contentId));
        }

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
