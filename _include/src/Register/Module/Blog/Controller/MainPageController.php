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
use Register\Url\ContentUrlGenerator;
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
        ContentUrlGenerator  $contentUrlGenerator,
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
        $posts        = $this->postProvider->lastPostsArray($postsPerPage, $skipLastPostsNum);

        $output = '';
        foreach ($posts as $post) {
            $post['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
            $post['showComments']     = $this->showComments->get();
            $post['enabledComments']  = $this->enabledComments->get();
            $output                   .= $this->viewer->render('post', $post, BlogModule::class);
        }

        $totalPosts = $this->postProvider->publishedPostCount();
        $totalPages = (int)ceil($totalPosts / $postsPerPage);
        $currentPage = intdiv($skipLastPostsNum, $postsPerPage) + 1;

        if ($totalPages > 1) {
            $prevLink = $currentPage > 1 ? $this->pageUrl($currentPage - 1, $postsPerPage) : null;
            $nextLink = $currentPage < $totalPages ? $this->pageUrl($currentPage + 1, $postsPerPage) : null;

            if ($prevLink !== null) {
                $template->setLink('prev', $prevLink);
            }

            if ($nextLink !== null) {
                $template->setLink('next', $nextLink);
            }

            $output .= $this->viewer->render('pagination', [
                'pages'        => $this->paginationItems($currentPage, $totalPages, $postsPerPage),
                'previous_url' => $prevLink,
                'next_url'     => $nextLink,
            ], BlogModule::class);
        } elseif ($skipLastPostsNum > 0) {
            $prevLink = $this->blogUrlBuilder->main();
            $template->setLink('prev', $prevLink);
        }

        $template->putInPlaceholder('text', $output);

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        if ($skipLastPostsNum > 0) {
            $template->setLink('up', $this->blogUrlBuilder->main());
        } else {
            $template->putInPlaceholder('meta_description', $this->blogTitle);
        }

        return null;
    }

    private function pageUrl(int $page, int $postsPerPage): string
    {
        if ($page <= 1) {
            return $this->blogUrlBuilder->main();
        }

        return $this->blogUrlBuilder->main() . 'skip/' . (($page - 1) * $postsPerPage);
    }

    /**
     * @return list<array{number: int|null, url: string|null, current: bool}>
     */
    private function paginationItems(int $currentPage, int $totalPages, int $postsPerPage): array
    {
        $visiblePages = [1, $totalPages];
        for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); ++$page) {
            $visiblePages[] = $page;
        }

        $visiblePages = array_values(array_unique($visiblePages));
        sort($visiblePages);

        $items = [];
        $previousPage = null;
        foreach ($visiblePages as $page) {
            if ($previousPage !== null && $page - $previousPage > 1) {
                $items[] = ['number' => null, 'url' => null, 'current' => false];
            }

            $isCurrent = $page === $currentPage;
            $items[] = [
                'number'  => $page,
                'url'     => $isCurrent ? null : $this->pageUrl($page, $postsPerPage),
                'current' => $isCurrent,
            ];
            $previousPage = $page;
        }

        return $items;
    }
}
