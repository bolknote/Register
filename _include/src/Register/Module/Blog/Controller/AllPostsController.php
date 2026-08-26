<?php

declare(strict_types = 1);

/**
 * Technical index of all published blog posts.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Blog\Model\AllPostsPage;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Register\Core\Pdo\DbLayerException;
use Register\Core\Template\HtmlTemplate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AllPostsController extends BlogController
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
        private readonly BlogPageCache $pageCache,
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
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse(
                $this->urlBuilder->link($request->getPathInfo() . '/'),
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $page = $this->pageCache->allPosts(function (): AllPostsPage {
            $posts = $this->postProvider->allPublishedPostLinks();
            $count = \count($posts);
            $title = $this->translator->trans('N Posts', [
                '%count%'     => $count,
                '{{ count }}' => $count,
            ]);

            return new AllPostsPage(
                $title,
                $this->viewer->render(
                    'all_posts',
                    ['posts' => $posts, 'title' => $title],
                    BlogModule::class,
                ),
            );
        });

        $template
            ->putInPlaceholder('head_title', $page->title)
            ->putInPlaceholder('canonical_path', $this->blogUrlBuilder->all())
            ->putInPlaceholder('text', $page->html)
            ->setLink('up', $this->blogUrlBuilder->main())
        ;

        return null;
    }
}
