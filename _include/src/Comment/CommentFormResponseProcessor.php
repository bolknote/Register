<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

use Register\Core\Framework\ResponseProcessorInterface;
use Register\Core\Template\PartialPageResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hydrates private comment forms after a shareable page shell has left the response cache. */
final readonly class CommentFormResponseProcessor implements ResponseProcessorInterface
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @param \Closure(): CommentFormRenderer $rendererProvider */
    public function __construct(private \Closure $rendererProvider)
    {
    }

    #[\Override]
    public function process(Request $request, Response $response): Response
    {
        $content = $response->getContent();
        if (!\is_string($content) || !DeferredCommentForm::existsIn($content)) {
            return $response;
        }

        $renderer = ($this->rendererProvider)();
        $session = null;
        $render = function (string $contentId) use ($renderer, $request, &$session): string {
            $session ??= $renderer->createSession($request);

            return $renderer->render($request, ['id' => $contentId], $session);
        };

        if ($this->isPartialPageResponse($response)) {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload) || !\is_string($payload['fragment'] ?? null)) {
                return $response;
            }

            $fragment = DeferredCommentForm::replace($payload['fragment'], $render);
            if ($fragment === null) {
                return $response;
            }

            $payload['fragment'] = $fragment;
            $content = json_encode($payload, self::JSON_FLAGS);
        } else {
            $hydrated = DeferredCommentForm::replace($content, $render);
            if ($hydrated === null) {
                return $response;
            }

            $content = $hydrated;
        }

        $response->setContent($content);
        if ($session instanceof CommentFormRenderSession) {
            $response->headers->setCookie($session->visitorCookie);
        }

        // The cached validator describes the shared shell. The client receives a request-bound form.
        $response->setEtag(md5($content));
        $response->headers->remove('Last-Modified');
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function isPartialPageResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return \is_string($contentType)
            && str_starts_with($contentType, PartialPageResponse::RESPONSE_CONTENT_TYPE);
    }
}
