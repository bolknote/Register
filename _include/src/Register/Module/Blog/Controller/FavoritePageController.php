<?php

declare(strict_types = 1);

/**
 * Favorite blog posts.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Model\ArticleProvider;
use Register\Model\FavoriteArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\QueryBuilder\SelectBuilder;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Register\Core\Pdo\DbLayerException;
use Symfony\Contracts\Translation\TranslatorInterface;

class FavoritePageController extends BlogController
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
        private readonly FavoriteArticleProvider $favoriteArticleProvider,
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
            return new RedirectResponse($this->urlBuilder->link($request->getPathInfo() . '/'), Response::HTTP_MOVED_PERMANENTLY);
        }

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $output = $this->getPosts(
            fn(SelectBuilder $qb): \Register\Core\Pdo\QueryBuilder\SelectBuilder => $qb
                ->addSelect('2 AS favorite')
                ->andWhere('p.featured = 1'),
            false
        );

        $favoriteArticleOutput = $this->favoriteArticleProvider->renderList();

        if ($output === '' && $favoriteArticleOutput === '') {
            // TODO Why 404 in favorite? Where is the message?
            $template->markAsNotFound();
        }

        // Bread crumbs
        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

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
