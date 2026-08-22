<?php
/**
 * Displays tags list page.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\TagsProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class PageTags implements ControllerInterface
{
    public function __construct(
        private TagsProvider         $tagsProvider,
        private ArticleProvider      $articleProvider,
        private UrlBuilder           $urlBuilder,
        private TranslatorInterface  $translator,
        private HtmlTemplateProvider $htmlTemplateProvider,
        private Viewer               $viewer
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse($this->urlBuilder->link($request->getPathInfo() . '/'), Response::HTTP_MOVED_PERMANENTLY);
        }

        $template = $this->htmlTemplateProvider->getTemplate('site.php');

        $template
            ->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'))
            ->addBreadCrumb($this->translator->trans('Tags'))
            ->putInPlaceholder('title', $this->translator->trans('Tags'))
            ->putInPlaceholder('date', '')
            ->putInPlaceholder('text', $this->viewer->render('tags_list', [
                'tags' => $this->tagsProvider->tagsList(),
            ]))
        ;

        return $template->toHttpResponse();
    }
}
