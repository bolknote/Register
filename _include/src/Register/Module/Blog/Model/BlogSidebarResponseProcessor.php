<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Core\Framework\ResponseProcessorInterface;
use Register\Core\Template\PartialPageResponse;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Hydrates independently cached, request-aware sidebar blocks after a page-cache hit. */
final readonly class BlogSidebarResponseProcessor implements ResponseProcessorInterface
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private BlogPlaceholderProvider $placeholderProvider,
        private Viewer                  $viewer,
        private TranslatorInterface     $translator,
    ) {
    }

    /** @suppress PhanUnusedPublicFinalMethodParameter Required by the response-processor contract. */
    #[\Override]
    public function process(Request $request, Response $response): Response
    {
        $content = $response->getContent();
        if (!\is_string($content) || !DeferredBlogSidebar::existsIn($content)) {
            return $response;
        }

        if ($this->isPartialPageResponse($response)) {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload) || !\is_string($payload['fragment'] ?? null)) {
                return $response;
            }

            $fragment = DeferredBlogSidebar::replace($payload['fragment'], $this->render(...));
            if ($fragment === null) {
                return $response;
            }

            $payload['fragment'] = $fragment;
            $content = json_encode($payload, self::JSON_FLAGS);
        } else {
            $hydrated = DeferredBlogSidebar::replace($content, $this->render(...));
            if ($hydrated === null) {
                return $response;
            }

            $content = $hydrated;
        }

        $response->setContent($content);
        $response->setEtag(md5($content));
        $response->headers->remove('Last-Modified');
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function render(string $slot): string
    {
        if ($slot === DeferredBlogSidebar::RECENT_COMMENTS) {
            $comments = $this->placeholderProvider->getRecentComments();

            return $comments === [] ? '' : $this->viewer->render('menu_comments', [
                'title' => $this->translator->trans('Last blog comments'),
                'menu'  => $comments,
            ]);
        }

        if ($slot === DeferredBlogSidebar::RECENT_DISCUSSIONS) {
            $discussions = $this->placeholderProvider->getRecentDiscussions();

            return $discussions === [] ? '' : $this->viewer->render('menu_block', [
                'title' => $this->translator->trans('Last blog discussions'),
                'menu'  => $discussions,
                'class' => 'register_blog_last_discussions',
            ]);
        }

        throw new \InvalidArgumentException('Unknown deferred blog sidebar slot.');
    }

    private function isPartialPageResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return \is_string($contentType)
            && str_starts_with($contentType, PartialPageResponse::RESPONSE_CONTENT_TYPE);
    }
}
