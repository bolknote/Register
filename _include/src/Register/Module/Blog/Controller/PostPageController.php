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

use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\Comment\CommentModerationContext;
use S2\Cms\Model\Comment\CommentThreadRenderer;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\HtmlTemplate;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use S2\Rose\Entity\ExternalId;
use Register\Module\Search\Module as SearchModule;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\SearchDocumentFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Cache\InvalidArgumentException;
use S2\Cms\Pdo\DbLayerException;

class PostPageController extends BlogController
{
    public function __construct(
        DbLayer                                  $dbLayer,
        CalendarBuilder                          $calendarBuilder,
        BlogUrlBuilder                           $blogUrlBuilder,
        ArticleProvider                          $articleProvider,
        PostProvider                             $postProvider,
        UrlBuilder                               $urlBuilder,
        private readonly ?RecommendationProvider $recommendationProvider,
        TranslatorInterface                      $translator,
        HtmlTemplateProvider                     $templateProvider,
        Viewer                                   $viewer,
        private readonly CommentThreadRenderer   $commentThreadRenderer,
        private readonly AuthProvider             $authProvider,
        private readonly TagRepository            $tagRepository,
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
        $url = $request->attributes->getString('url');

        $template->putInPlaceholder('title', '');

        $result = $this->getPost($request, $template, $url);
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));
        if (!$this->blogUrlBuilder->blogIsOnTheSiteRoot()) {
            $template->addBreadCrumb($this->translator->trans('Blog'), $this->blogUrlBuilder->main());
        }

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
                'create_time, display_date, title, text, id, commented, label, favorite',
                '(' . $this->dbLayer
                    ->select('u.name')
                    ->from('users AS u')
                    ->where('u.id = p.user_id')
                    ->getSql() . ') AS author',
                'url'
            )
            ->from('s2_blog_posts AS p')
            ->where('url = :url')->setParameter('url', $url)
            ->andWhere('published = 1')
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row === false) {
            $template
                ->putInPlaceholder('head_title', $this->translator->trans('Not found'))
                ->putInPlaceholder('text', '<p>' . $this->translator->trans('Not found') . '</p>')
            ;

            return $template->toHttpResponse()->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        $post_id = $row['id'];
        $label   = (string)$row['label'];

        if ($template->hasPlaceholder('<!-- s2_blog_calendar -->')) {
            $createTime = (int)$row['create_time'];
            $template->registerPlaceholder('<!-- s2_blog_calendar -->', $this->calendarBuilder->calendar(
                (int)date('Y', $createTime),
                (int)date('m', $createTime),
                (int)date('d', $createTime),
                $url
            ));
        }

        $template->putInPlaceholder('canonical_path', $this->blogUrlBuilder->post($row['url']));

        $is_back_forward = $template->hasPlaceholder('<!-- s2_blog_back_forward -->');
        $queries = [];
        $params = [];
        if ($label !== '') {
            // Getting posts that have the same label
            $queries[]         = $this->dbLayer->select('title, create_time, url, "label" AS type')
                ->from('s2_blog_posts')
                ->where('label = :label')
                ->andWhere('id <> :post_id')
                ->andWhere('published = 1')
                ->orderBy('create_time DESC')
                ->getSql()
            ;
            $params['label']   = $label;
            $params['post_id'] = $post_id;
        }

        if ($is_back_forward) {
            $queries[] = $this->dbLayer->select('title, create_time, url, "next" AS type')
                ->from('s2_blog_posts')
                ->where('create_time > :time_next')
                ->andWhere('published = 1')
                ->orderBy('create_time ASC')
                ->limit(1)
                ->getSql()
            ;

            $params['time_next'] = (int)$row['create_time'];

            $queries[] = $this->dbLayer->select('title, create_time, url, "prev" AS type')
                ->from('s2_blog_posts')
                ->where('create_time < :time_prev')
                ->setParameter('time_prev', (int)$row['create_time'], \PDO::PARAM_INT)
                ->andWhere('published = 1')
                ->orderBy('create_time DESC')
                ->limit(1)
                ->getSql()
            ;

            $params['time_prev'] = (int)$row['create_time'];
        }

        $result = $queries !== [] ? $this->dbLayer->query('(' . implode(') UNION (', $queries) . ')', $params) : null;

        $back_forward = [];
        while ($result instanceof \S2\Cms\Pdo\QueryResult && ($row1 = $result->fetchAssoc()) !== false) {
            $post_info = [
                'title' => $row1['title'],
                'link'  => $this->blogUrlBuilder->post($row1['url']),
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
            $template->registerPlaceholder('<!-- s2_blog_back_forward -->', $this->viewer->render('back_forward_post', $back_forward, BlogModule::class));
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

        $template->putInPlaceholder('commented', $row['commented']);
        if ((bool)$row['commented'] && $this->showComments->get() && $template->hasPlaceholder('<!-- s2_comments -->')) {
            $template->putInPlaceholder('comments', $this->getComments($post_id, $request));
        }

        $row['time']             = $this->postProvider->displayDate((int)$row['create_time'], (string)$row['display_date']);
        $row['commented']        = 0; // for template
        $row['tags']             = $tags;
        $row['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
        $row['showComments']     = $this->showComments->get();
        $row['enabledComments']  = $this->enabledComments->get();

        $template
            ->putInPlaceholder('meta_description', $this->extractMetaDescriptions($row['text']))
            ->putInPlaceholder('text', $this->viewer->render('post', $row, BlogModule::class))
            ->putInPlaceholder('id', md5('s2_blog_post_' . $post_id))
            ->putInPlaceholder('head_title', s2_htmlencode($row['title']))
        ;

        if ($this->recommendationProvider instanceof RecommendationProvider && $template->hasPlaceholder('<!-- s2_recommendations -->')) {
            $request_uri = $request->getPathInfo();
            [$recommendations, $log, $rawRecommendations] = $this->recommendationProvider->getRecommendations(
                $request_uri,
                new ExternalId(SearchDocumentFactory::externalId(ContentId::post((int)$post_id))),
            );
            $template->putInPlaceholder('recommendations', $this->viewer->render('recommendations', [
                'raw'     => $rawRecommendations,
                'content' => $recommendations,
                'log'     => $log,
            ], SearchModule::class));
        }

        return null;
    }

    /**
     * @throws DbLayerException
     */
    private function getComments(int $id, Request $request): string
    {
        $authorComment = $this->dbLayer
            ->select('COUNT(*)')
            ->from('users AS u')
            ->where('LOWER(u.email) = LOWER(c.email)')
            ->andWhere("c.email <> ''")
            ->getSql()
        ;
        $moderatorLabel = $this->dbLayer
            ->select('sa.moderator_label')
            ->from('spam_assessments AS sa')
            ->where("sa.target_type = 'post'")
            ->andWhere('sa.comment_id = c.id')
            ->orderBy('sa.id DESC')
            ->limit(1)
            ->getSql()
        ;
        $statement = $this->dbLayer
            ->select(
                'c.id, c.parent_id, c.nick, c.time, c.email, c.show_email, c.good, c.text, c.shown, c.deleted, p.storage_key AS userpic_storage_key',
                '(' . $authorComment . ') AS is_author',
                '(' . $moderatorLabel . ') AS moderator_label',
            )
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->leftJoin('userpics AS p', 'p.id = c.userpic_id')
            ->where('c.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('c.content_id = :content_id')->setParameter('content_id', $id)
            ->orderBy('time, c.id')
            ->execute()
        ;

        $moderator = $this->authProvider->getAuthenticatedCommentModerator($request);

        return $this->commentThreadRenderer->render(
            $statement->fetchAssocAll(),
            $moderator === null ? null : new CommentModerationContext($moderator, ContentType::POST, $request->getPathInfo()),
        );
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
