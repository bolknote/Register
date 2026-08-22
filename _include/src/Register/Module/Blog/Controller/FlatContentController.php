<?php

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlAliasController;
use Register\Core\Controller\PageCommon;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
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
        private PostProvider       $postProvider,
        private UrlBuilder         $urlBuilder,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($this->articleProvider->articleFromPath($request->getPathInfo(), true) !== null) {
            return $this->pageController->handle($request);
        }

        $redirect = $this->aliasController->redirect($request);
        if ($redirect instanceof RedirectResponse) {
            return $redirect;
        }

        $path = $request->getPathInfo();
        $slug = rtrim($request->attributes->getString('url'), '/');
        if ($path !== '/' && str_ends_with($path, '/') && $slug !== '' && $this->postProvider->hasPublishedPost($slug)) {
            $target = $this->urlBuilder->link(rtrim($path, '/'));
            $query = $request->getQueryString();
            if (is_string($query) && $query !== '') {
                $target .= '?' . $query;
            }

            return new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->postController->handle($request);
    }
}
