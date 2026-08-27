<?php

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Content\ContentId;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\BlogResponseCachePolicy;
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
    public const string CONTENT_ID_ATTRIBUTE = '_register_blog_content_id';

    public const string SHARED_RESPONSE_ATTRIBUTE = '_register_blog_shared_response';

    public function __construct(
        private ArticleProvider    $articleProvider,
        private PageCommon         $pageController,
        private PostPageController $postController,
        private ContentUrlAliasController $aliasController,
        private PostProvider       $postProvider,
        private UrlBuilder         $urlBuilder,
        private BlogPageCache      $pageCache,
        private BlogResponseCachePolicy $responseCachePolicy,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $variant = $this->responseCachePolicy->variant($request);
        if ($variant !== null) {
            $request->attributes->set(self::SHARED_RESPONSE_ATTRIBUTE, str_ends_with($variant, '_bot'));

            return $this->pageCache->contentResponse(
                $variant,
                $request->getPathInfo(),
                fn(): Response => $this->resolve($request, true),
            );
        }

        return $this->resolve($request, false);
    }

    private function resolve(Request $request, bool $rememberContent): Response
    {
        $article = $this->articleProvider->articleFromPath($request->getPathInfo(), true);
        if ($article !== null) {
            $response = $this->pageController->handle($request);
            $articleId = (int)($article['id'] ?? 0);
            if ($rememberContent && $articleId > 0 && $response->getStatusCode() === Response::HTTP_OK) {
                $this->pageCache->rememberContentPath(ContentId::page($articleId), $request->getPathInfo());
            }

            return $response;
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

        $response = $this->postController->handle($request);
        $contentId = $request->attributes->get(self::CONTENT_ID_ATTRIBUTE);
        if ($rememberContent && $contentId instanceof ContentId && $response->getStatusCode() === Response::HTTP_OK) {
            $this->pageCache->rememberContentPath($contentId, $request->getPathInfo());
        }

        return $response;
    }
}
