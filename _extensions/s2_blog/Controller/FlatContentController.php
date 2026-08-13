<?php

declare(strict_types = 1);

namespace s2_extensions\s2_blog\Controller;

use S2\Cms\Controller\PageCommon;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Model\ArticleProvider;
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
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($this->articleProvider->articleFromPath($request->getPathInfo(), true) !== null) {
            return $this->pageController->handle($request);
        }

        return $this->postController->handle($request);
    }
}
