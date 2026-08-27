<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Content\ContentViewRepository;
use Register\Core\Framework\ResponseProcessorInterface;
use Register\Module\Analytics\BotDetector;
use Register\Core\Template\PartialPageResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Records every real content view and hydrates exact counters after a page-cache hit. */
final readonly class ContentViewResponseProcessor implements ResponseProcessorInterface
{
    public const string CONTENT_ID_HEADER = 'X-Register-Rendered-Content';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private ContentViewRepository $views,
        private BotDetector           $botDetector,
        private TranslatorInterface   $translator,
    ) {
    }

    #[\Override]
    public function process(Request $request, Response $response): Response
    {
        try {
            $this->recordPrimaryContent($request, $response);

            $content = $response->getContent();
            if (!\is_string($content) || !DeferredViewCount::existsIn($content)) {
                return $response;
            }

            $partial = $this->isPartialPageResponse($response);
            $fragment = $content;
            $payload = null;
            if ($partial) {
                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($payload) || !\is_string($payload['fragment'] ?? null)) {
                    return $response;
                }

                $fragment = $payload['fragment'];
            }

            $totals = $this->views->totals(DeferredViewCount::contentIds($fragment));
            $hydrated = DeferredViewCount::replace(
                $fragment,
                fn(ContentId $contentId): string => $this->render($totals[(string)$contentId] ?? 0),
            );
            if (!\is_string($hydrated)) {
                throw new \RuntimeException('Unable to hydrate deferred view counters.');
            }

            if ($partial) {
                $payload['fragment'] = $hydrated;
                $content = json_encode($payload, self::JSON_FLAGS);
            } else {
                $content = $hydrated;
            }

            $response->setContent($content);
            $response->setEtag(md5($content));
            $response->headers->remove('Last-Modified');
            $response->headers->remove('Content-Length');

            return $response;
        } finally {
            $response->headers->remove(self::CONTENT_ID_HEADER);
        }
    }

    private function recordPrimaryContent(Request $request, Response $response): void
    {
        if (!$request->isMethod(Request::METHOD_GET) || $this->isNonInteractive($request)) {
            return;
        }

        $rawContentId = $response->headers->get(self::CONTENT_ID_HEADER);
        if (!\is_string($rawContentId) || $rawContentId === '') {
            return;
        }

        try {
            $contentId = ContentId::fromString($rawContentId);
        } catch (\Throwable) {
            return;
        }

        $this->views->record($contentId);
    }

    private function isNonInteractive(Request $request): bool
    {
        if ($this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')) {
            return true;
        }

        $purpose = strtolower(trim(implode(' ', [
            $request->headers->get('Purpose', '') ?? '',
            $request->headers->get('Sec-Purpose', '') ?? '',
        ])));

        return str_contains($purpose, 'prefetch');
    }

    private function render(int $count): string
    {
        $label = $this->translator->trans('N Views', [
            '%count%' => $count,
            '{{ count }}' => $count,
        ]);
        $encodedLabel = register_htmlencode($label);

        return '<span class="post-foot-views" aria-label="' . $encodedLabel
            . '" title="' . $encodedLabel . '"><span class="post-foot-views-count" aria-hidden="true">'
            . $count . '</span></span>';
    }

    private function isPartialPageResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return \is_string($contentType)
            && str_starts_with($contentType, PartialPageResponse::RESPONSE_CONTENT_TYPE);
    }
}
