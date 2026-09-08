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
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Module;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hydrates post graph and calendar fragments from one independently invalidated index. */
final readonly class PostPageContextResponseProcessor implements ResponseProcessorInterface
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private PostPageContextProvider $contextProvider,
        private CalendarBuilder         $calendarBuilder,
        private Viewer                  $viewer,
    ) {
    }

    /** @suppress PhanUnusedPublicFinalMethodParameter Required by the response-processor contract. */
    #[\Override]
    public function process(Request $request, Response $response): Response
    {
        $content = $response->getContent();
        if (!\is_string($content) || !DeferredPostPageContext::existsIn($content)) {
            return $response;
        }

        /** @var array<int, PostPageContext|null> $contexts */
        $contexts = [];
        $renderer = function (string $slot, int $postId) use (&$contexts): string {
            if (!array_key_exists($postId, $contexts)) {
                $contexts[$postId] = $this->contextProvider->forPost($postId);
            }

            $context = $contexts[$postId];

            return $context instanceof PostPageContext ? $this->render($slot, $context) : '';
        };

        if ($this->isPartialPageResponse($response)) {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload)) {
                return $response;
            }

            $changed = false;
            foreach (['head', 'fragment'] as $field) {
                if (!\is_string($payload[$field] ?? null)) {
                    continue;
                }

                $hydrated = DeferredPostPageContext::replace($payload[$field], $renderer);
                if ($hydrated !== null) {
                    $payload[$field] = $hydrated;
                    $changed = true;
                }
            }

            if (!$changed) {
                return $response;
            }

            $content = json_encode($payload, self::JSON_FLAGS);
        } else {
            $hydrated = DeferredPostPageContext::replace($content, $renderer);
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

    private function render(string $slot, PostPageContext $context): string
    {
        return match ($slot) {
            DeferredPostPageContext::AUTHOR => register_htmlencode($context->author),
            DeferredPostPageContext::SEE_ALSO => $context->seeAlso === [] ? '' : $this->viewer->render(
                'see_also',
                ['see_also' => $context->seeAlso],
                Module::class,
            ),
            DeferredPostPageContext::BACK_FORWARD => $context->back === null && $context->forward === null
                ? ''
                : $this->viewer->render('back_forward_post', [
                    'back'    => $context->back,
                    'forward' => $context->forward,
                ], Module::class),
            DeferredPostPageContext::HEAD_LINKS => $this->renderHeadLinks($context),
            DeferredPostPageContext::CALENDAR => $this->calendarBuilder->calendar(
                $context->year,
                $context->month,
                $context->day,
                $context->slug,
                $context->dayUrls,
            ),
            default => throw new \InvalidArgumentException('Unknown deferred post-context slot.'),
        };
    }

    private function renderHeadLinks(PostPageContext $context): string
    {
        $links = [];
        if ($context->back !== null) {
            $links[] = '<link rel="prev" href="' . register_htmlencode($context->back['link']) . '" />';
        }

        if ($context->forward !== null) {
            $links[] = '<link rel="next" href="' . register_htmlencode($context->forward['link']) . '" />';
        }

        return implode("\n", $links);
    }

    private function isPartialPageResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return \is_string($contentType)
            && str_starts_with($contentType, PartialPageResponse::RESPONSE_CONTENT_TYPE);
    }
}
