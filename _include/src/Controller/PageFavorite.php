<?php
/**
 * Displays the list of favorite pages and excerpts.
 *
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\FavoriteArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

readonly class PageFavorite implements ControllerInterface
{
    public function __construct(
        private FavoriteArticleProvider $favoriteArticleProvider,
        private ArticleProvider         $articleProvider,
        private UrlBuilder              $urlBuilder,
        private TranslatorInterface     $translator,
        private HtmlTemplateProvider    $htmlTemplateProvider,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse(
                $this->urlBuilder->link($request->getPathInfo() . '/'),
                Response::HTTP_MOVED_PERMANENTLY
            );
        }

        $template = $this->htmlTemplateProvider->getTemplate('site.php');

        $template
            ->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'))
            ->addBreadCrumb($this->translator->trans('Favorite'))
            ->putInPlaceholder('title', $this->translator->trans('Favorite'))
            ->putInPlaceholder('date', '')
            ->putInPlaceholder('text', $this->favoriteArticleProvider->renderList())
        ;

        return $template->toHttpResponse();
    }
}
