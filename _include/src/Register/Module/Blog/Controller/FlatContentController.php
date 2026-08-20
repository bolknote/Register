<?php

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Url\ContentUrlAliasController;
use S2\Cms\Controller\PageCommon;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a short root-level URL without hiding permanent pages.
 */
readonly class FlatContentController implements ControllerInterface
{
    public function __construct(
        private ArticleProvider    $articleProvider,
        private PageCommon         $pageController,
        private PostPageController $postController,
        private ContentUrlAliasController $aliasController,
        private UrlBuilder         $urlBuilder,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($this->articleProvider->articleFromPath($request->getPathInfo(), true) !== null) {
            return $this->pageController->handle($request);
        }

        $path = $request->getPathInfo();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $target = $this->urlBuilder->link(rtrim($path, '/'));
            $query = $request->getQueryString();
            if (is_string($query) && $query !== '') {
                $target .= '?' . $query;
            }

            return new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY);
        }

        $redirect = $this->aliasController->redirect($request);
        if ($redirect instanceof RedirectResponse) {
            return $redirect;
        }

        return $this->postController->handle($request);
    }
}
