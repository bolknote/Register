<?php

declare(strict_types = 1);

/**
 * Favorite blog posts.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_blog
 */

namespace s2_extensions\s2_blog\Controller;

use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\FavoriteArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\QueryBuilder\SelectBuilder;
use S2\Cms\Template\HtmlTemplate;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use s2_extensions\s2_blog\BlogUrlBuilder;
use s2_extensions\s2_blog\CalendarBuilder;
use s2_extensions\s2_blog\Model\PostProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use S2\Cms\Pdo\DbLayerException;
use Symfony\Contracts\Translation\TranslatorInterface;

class FavoritePageController extends BlogController
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
        private readonly FavoriteArticleProvider $favoriteArticleProvider,
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
            return new RedirectResponse($this->urlBuilder->link($request->getPathInfo() . '/'), Response::HTTP_MOVED_PERMANENTLY);
        }

        if ($template->hasPlaceholder('<!-- s2_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- s2_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $output = $this->getPosts(
            fn(SelectBuilder $qb): \S2\Cms\Pdo\QueryBuilder\SelectBuilder => $qb
                ->addSelect('2 AS favorite')
                ->andWhere('p.favorite = 1'),
            false
        );

        $favoriteArticleOutput = $this->favoriteArticleProvider->renderList();

        if ($output === '' && $favoriteArticleOutput === '') {
            // TODO Why 404 in favorite? Where is the message?
            $template->markAsNotFound();
        }

        // Bread crumbs
        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));
        if (!$this->blogUrlBuilder->blogIsOnTheSiteRoot()) {
            $template->addBreadCrumb($this->translator->trans('Blog'), $this->blogUrlBuilder->main());
        }

        $template->addBreadCrumb($this->translator->trans('Favorite'));

        $template
            ->putInPlaceholder('head_title', $this->translator->trans('Favorite'))
            ->putInPlaceholder('title', $this->translator->trans('Favorite'))
            ->putInPlaceholder('text', $favoriteArticleOutput . $output)
        ;

        $template->setLink('up', $this->blogUrlBuilder->main());

        return null;
    }
}
