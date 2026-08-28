<?php

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Controller\CommentController;
use Register\Core\Framework\ControllerInterface;
use Register\Model\ArticleProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends comments for permanent pages and short blog URLs to the right strategy.
 */
readonly class FlatCommentController implements ControllerInterface
{
    public function __construct(
        private ArticleProvider   $articleProvider,
        private CommentController $articleCommentController,
        private CommentController $blogCommentController,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($this->articleProvider->articleFromPath($request->getPathInfo(), true) !== null) {
            return $this->articleCommentController->handle($request);
        }

        return $this->blogCommentController->handle($request);
    }
}
