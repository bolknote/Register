<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Psr\Log\LoggerInterface;
use Register\Core\Framework\ControllerInterface;
use Register\Module\VisitorIdentity\JsonMutationGuard;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Same-origin, cookie-authenticated collection endpoint with a disk-first write path. */
final readonly class AnalyticsCollectorController implements ControllerInterface
{
    private const int MAX_BODY_BYTES = 32768;

    private const int MAX_EVENTS = 20;

    public function __construct(
        private VisitorIdentityManager   $identityManager,
        private JsonMutationGuard        $mutationGuard,
        private BotDetector              $botDetector,
        private AnalyticsEventNormalizer $normalizer,
        private AnalyticsRateLimiter     $rateLimiter,
        private AnalyticsSpool           $spool,
        private AnalyticsIngestor        $ingestor,
        private LoggerInterface          $logger,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->jsonError('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED, [
                'Allow' => Request::METHOD_POST,
            ]);
        }

        $violation = $this->mutationGuard->violation($request);
        if ($violation instanceof JsonResponse) {
            $violation->headers->set('Cache-Control', 'no-store, private');
            return $violation;
        }

        if ($this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')) {
            return $this->accepted();
        }

        $visitorId = $this->identityManager->visitorIdFromRequest($request);
        if ($visitorId === null) {
            return $this->accepted();
        }

        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return $this->jsonError('The request is too large.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonError('Malformed JSON.', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return $this->jsonError('Unsupported analytics payload.', Response::HTTP_BAD_REQUEST);
        }

        $rawEvents = $payload['events'] ?? null;
        if (!\is_array($rawEvents)
            || !array_is_list($rawEvents)
            || $rawEvents === []
            || \count($rawEvents) > self::MAX_EVENTS
        ) {
            return $this->jsonError('An analytics event batch is required.', Response::HTTP_BAD_REQUEST);
        }

        $receivedAt = time();
        $events     = [];
        try {
            foreach ($rawEvents as $rawEvent) {
                if (!\is_array($rawEvent)) {
                    throw new \InvalidArgumentException('Each analytics event must be an object.');
                }

                $events[] = $this->normalizer->normalize($rawEvent, $request, $visitorId, $receivedAt);
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        if (!$this->rateLimiter->accepts($request, $events[0]->visitorKey, \count($events), $receivedAt)) {
            return $this->jsonError('Analytics rate limit exceeded.', Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => '60',
            ]);
        }

        try {
            $this->spool->append($events, $receivedAt);
        } catch (AnalyticsSpoolException $spoolException) {
            $this->logger->warning('Analytics spool is unavailable; falling back to direct ingestion.', [
                'exception' => $spoolException,
            ]);
            try {
                $this->ingestor->ingest($events);
            } catch (\Throwable $ingestionFailure) {
                // Analytics must not become an availability dependency of the public site.
                $this->logger->error('Analytics event batch was dropped after both write paths failed.', [
                    'exception' => $ingestionFailure,
                ]);
            }
        }

        return $this->accepted();
    }

    private function accepted(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT, [
            'Cache-Control'         => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, string> $headers */
    private function jsonError(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['success' => false, 'message' => $message],
            $status,
            ['Cache-Control' => 'no-store, private'] + $headers,
        );
    }
}
