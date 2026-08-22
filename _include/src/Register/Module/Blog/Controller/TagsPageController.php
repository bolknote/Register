<?php

declare(strict_types = 1);

/**
 * List of blog tags.
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Content\TagRepository;
use Register\Url\ContentUrlGenerator;
use Register\Core\Template\HtmlTemplate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Register\Core\Pdo\DbLayerException;

class TagsPageController extends BlogController
{
    public function __construct(
        \Register\Core\Pdo\DbLayer $dbLayer,
        \Register\Module\Blog\CalendarBuilder $calendarBuilder,
        \Register\Module\Blog\BlogUrlBuilder $blogUrlBuilder,
        \Register\Core\Model\ArticleProvider $articleProvider,
        \Register\Module\Blog\Model\PostProvider $postProvider,
        ContentUrlGenerator $contentUrlGenerator,
        \Register\Core\Model\UrlBuilder $urlBuilder,
        \Symfony\Contracts\Translation\TranslatorInterface $translator,
        \Register\Core\Template\HtmlTemplateProvider $templateProvider,
        \Register\Core\Template\Viewer $viewer,
        \Register\Core\Config\StringProxy $blogTitle,
        \Register\Core\Config\BoolProxy $showComments,
        \Register\Core\Config\BoolProxy $enabledComments,
        private readonly TagRepository $tagRepository,
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

        $template->registerPlaceholder('<!-- register_blog_navigation -->', '');

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $tags = [];
        foreach ($this->tagRepository->findPublishedUsage() as $usage) {
            $tags[] = [
                'title' => $usage->tag->name,
                'link'  => $this->blogUrlBuilder->tag($usage->tag->slug),
                'num'   => $usage->publishedContentCount,
            ];
        }

        $template->putInPlaceholder('text', $this->viewer->render('tags_list', ['tags' => $tags]));

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        $template->addBreadCrumb($this->translator->trans('Tags'));

        $template
            ->putInPlaceholder('head_title', $this->translator->trans('Tags'))
            ->putInPlaceholder('title', $this->translator->trans('Tags'))
            ->setLink('up', $this->blogUrlBuilder->main())
        ;

        return null;
    }
}
