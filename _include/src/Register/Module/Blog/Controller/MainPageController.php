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

use Register\Module\Blog\Module as BlogModule;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\IntProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\HtmlTemplate;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;

class MainPageController extends BlogController
{
    public function __construct(
        DbLayer              $dbLayer,
        CalendarBuilder      $calendarBuilder,
        BlogUrlBuilder       $blogUrlBuilder,
        ArticleProvider      $articleProvider,
        PostProvider         $postProvider,
        UrlBuilder           $urlBuilder,
        TranslatorInterface  $translator,
        HtmlTemplateProvider $templateProvider,
        Viewer               $viewer,
        StringProxy          $blogTitle,
        BoolProxy            $showComments,
        BoolProxy            $enabledComments,
        private readonly IntProxy $itemsPerPage,
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

    #[\Override]
    public function handle(Request $request): Response
    {
        $s2_blog_skip      = (int)$request->attributes->get('page', 0);
        $this->template_id = $s2_blog_skip > 0 ? 'blog.php' : 'blog_main.php';

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

        if ($template->hasPlaceholder('<!-- s2_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- s2_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $itemsPerPage = $this->itemsPerPage->get();
        $postsPerPage = $itemsPerPage > 0 ? $itemsPerPage : 10;
        $posts        = $this->postProvider->lastPostsArray($postsPerPage, $skipLastPostsNum, true);

        $output = '';
        $i      = 0;
        foreach ($posts as $post) {
            ++$i;
            if ($i > $postsPerPage) {
                break;
            }

            $post['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
            $post['showComments']     = $this->showComments->get();
            $post['enabledComments']  = $this->enabledComments->get();
            $output                   .= $this->viewer->render('post', $post, BlogModule::class);
        }

        $paging = '';
        if ($skipLastPostsNum > 0) {
            $prevLink = $this->blogUrlBuilder->main() . ($skipLastPostsNum > $postsPerPage ? 'skip/' . ($skipLastPostsNum - $postsPerPage) : '');
            $template->setLink('prev', $prevLink);
            $paging = '<a href="' . $prevLink . '">' . $this->translator->trans('Here') . '</a> ';
            // TODO think about back_forward
        }

        if ($i > $postsPerPage) {
            $nextLink = $this->blogUrlBuilder->main() . 'skip/' . ($skipLastPostsNum + $postsPerPage);
            $template->setLink('next', $nextLink);
            $paging .= '<a href="' . $nextLink . '">' . $this->translator->trans('There') . '</a>';
        }

        if ($paging !== '') {
            $output .= '<p class="s2_blog_pages">' . $paging . '</p>';
        }

        $template->putInPlaceholder('text', $output);

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));
        if (!$this->blogUrlBuilder->blogIsOnTheSiteRoot()) {
            $template->addBreadCrumb($this->translator->trans('Blog'), $skipLastPostsNum > 0 ? $this->blogUrlBuilder->main() : null);
        }

        if ($skipLastPostsNum > 0) {
            $template->setLink('up', $this->blogUrlBuilder->main());
        } else {
            $template->putInPlaceholder('meta_description', $this->blogTitle);
            if (!$this->blogUrlBuilder->blogIsOnTheSiteRoot()) {
                $template->setLink('up', $this->urlBuilder->link('/'));
            }
        }

        return null;
    }
}
