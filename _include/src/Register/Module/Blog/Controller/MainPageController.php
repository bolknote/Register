<?php

declare(strict_types = 1);

/**
 * Main blog page with last posts.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Live\LiveUpdateContext;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

class MainPageController extends BlogController
{
    public function __construct(
        DbLayer              $dbLayer,
        CalendarBuilder      $calendarBuilder,
        BlogUrlBuilder       $blogUrlBuilder,
        ArticleProvider      $articleProvider,
        PostProvider         $postProvider,
        ContentUrlGenerator  $contentUrlGenerator,
        UrlBuilder           $urlBuilder,
        TranslatorInterface  $translator,
        HtmlTemplateProvider $templateProvider,
        Viewer               $viewer,
        private readonly PostFeedRenderer  $postFeedRenderer,
        private readonly LiveUpdateContext $liveUpdates,
        StringProxy          $blogTitle,
        BoolProxy            $showComments,
        BoolProxy            $enabledComments,
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

    #[\Override]
    public function handle(Request $request): Response
    {
        $register_blog_skip      = (int)$request->attributes->get('page', 0);
        $this->template_id = $register_blog_skip > 0 ? 'blog.php' : 'blog_main.php';

        return parent::handle($request);
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse($this->urlBuilder->link($request->getPathInfo() . '/'), Response::HTTP_MOVED_PERMANENTLY);
        }

        $skipLastPostsNum = (int)$request->attributes->get('page', 0);
        if ($skipLastPostsNum < 0) {
            $skipLastPostsNum = 0;
        }

        $this->liveUpdates->subscribePosts($skipLastPostsNum);

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $feed = $this->postFeedRenderer->render($skipLastPostsNum, $request);
        if ($feed->previousUrl !== null) {
            $template->setLink('prev', $feed->previousUrl);
        }

        if ($feed->nextUrl !== null) {
            $template->setLink('next', $feed->nextUrl);
        }

        $template->putInPlaceholder('text', $feed->html);

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        if ($skipLastPostsNum > 0) {
            $template->setLink('up', $this->blogUrlBuilder->main());
        } else {
            $template->putInPlaceholder('meta_description', $this->blogTitle);
        }

        return null;
    }
}
