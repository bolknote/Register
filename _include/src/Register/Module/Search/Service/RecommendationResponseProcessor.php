<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Content\ContentId;
use Register\Core\Framework\ResponseProcessorInterface;
use Register\Core\Template\PartialPageResponse;
use Register\Core\Template\Viewer;
use Register\Module\Analytics\BotDetector;
use Register\Module\Search\Module;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Rose\Entity\ExternalId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hydrates independently cached recommendations after a complete page-cache hit. */
final readonly class RecommendationResponseProcessor implements ResponseProcessorInterface
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private RecommendationProvider  $recommendationProvider,
        private VisitorIdentityManager  $visitorIdentityManager,
        private Viewer                  $viewer,
        private BotDetector             $botDetector,
    ) {
    }

    #[\Override]
    public function process(Request $request, Response $response): Response
    {
        $content = $response->getContent();
        if (!\is_string($content) || !DeferredRecommendations::existsIn($content)) {
            return $response;
        }

        // Crawlers need the article, not a per-request layout of related links.
        // A warm recommendation cache would otherwise still be read and rendered
        // after every complete page-cache hit.
        $renderer = $this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')
            ? static fn(): string => ''
            : fn(ContentId $contentId): string => $this->render($request, $contentId);

        if ($this->isPartialPageResponse($response)) {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($payload) || !\is_string($payload['fragment'] ?? null)) {
                return $response;
            }

            $fragment = DeferredRecommendations::replace(
                $payload['fragment'],
                $renderer,
            );
            if ($fragment === null) {
                return $response;
            }

            $payload['fragment'] = $fragment;
            $content = json_encode($payload, self::JSON_FLAGS);
        } else {
            $hydrated = DeferredRecommendations::replace(
                $content,
                $renderer,
            );
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

    private function render(Request $request, ContentId $contentId): string
    {
        [$recommendations, , $rawRecommendations] = $this->recommendationProvider->getRecommendations(
            $request->getPathInfo(),
            new ExternalId(SearchDocumentFactory::externalId($contentId)),
            $this->visitorIdentityManager->visitorIdFromRequest($request) !== null,
        );

        return $this->viewer->render('recommendations', [
            'raw'     => $rawRecommendations,
            'content' => $recommendations,
        ], Module::class);
    }

    private function isPartialPageResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return \is_string($contentType)
            && str_starts_with($contentType, PartialPageResponse::RESPONSE_CONTENT_TYPE);
    }
}
