<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Http;

use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActivityPubResponseFactory
{
    public const string ACTIVITY_MEDIA_TYPE = 'application/activity+json';

    public const string JRD_MEDIA_TYPE = 'application/jrd+json';

    public const string NODEINFO_MEDIA_TYPE = 'application/json';

    /** @param array<string, mixed> $document */
    public function activity(Request $request, array $document, int $status = Response::HTTP_OK): Response
    {
        if (!$this->accepts($request, [
            self::ACTIVITY_MEDIA_TYPE,
            'application/ld+json',
            'application/json',
        ])) {
            return $this->notAcceptable($request);
        }

        return $this->encoded($request, $this->encode($document), self::ACTIVITY_MEDIA_TYPE, $status);
    }

    /** @param array<string, mixed> $document */
    public function activationProbeActivity(Request $request, array $document): Response
    {
        if (!$this->accepts($request, [
            self::ACTIVITY_MEDIA_TYPE,
            'application/ld+json',
            'application/json',
        ])) {
            return $this->notAcceptable($request);
        }

        return $this->encoded($request, $this->encode($document), self::ACTIVITY_MEDIA_TYPE, Response::HTTP_OK, false);
    }

    public function serializedActivity(Request $request, string $document, int $status = Response::HTTP_OK): Response
    {
        if (!$this->accepts($request, [
            self::ACTIVITY_MEDIA_TYPE,
            'application/ld+json',
            'application/json',
        ])) {
            return $this->notAcceptable($request);
        }

        try {
            $decoded = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored ActivityPub document is invalid JSON.', 0, $exception);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('A stored ActivityPub document must be a JSON object.');
        }

        return $this->encoded($request, $document, self::ACTIVITY_MEDIA_TYPE, $status);
    }

    /** @param array<string, mixed> $document */
    public function webFinger(Request $request, array $document): Response
    {
        if (!$this->accepts($request, [self::JRD_MEDIA_TYPE, 'application/json'])) {
            return $this->notAcceptable($request);
        }

        return $this->encoded($request, $this->encode($document), self::JRD_MEDIA_TYPE, Response::HTTP_OK);
    }

    /** @param array<string, mixed> $document */
    public function activationProbeWebFinger(Request $request, array $document): Response
    {
        if (!$this->accepts($request, [self::JRD_MEDIA_TYPE, 'application/json'])) {
            return $this->notAcceptable($request);
        }

        return $this->encoded($request, $this->encode($document), self::JRD_MEDIA_TYPE, Response::HTTP_OK, false);
    }

    /** @param array<string, mixed> $document */
    public function nodeInfo(Request $request, array $document): Response
    {
        if (!$this->accepts($request, [self::NODEINFO_MEDIA_TYPE, 'application/jrd+json'])) {
            return $this->notAcceptable($request);
        }

        return $this->encoded($request, $this->encode($document), self::NODEINFO_MEDIA_TYPE, Response::HTTP_OK);
    }

    public function notFound(Request $request): Response
    {
        return $this->problem($request, Response::HTTP_NOT_FOUND, 'Not Found', 'The ActivityPub resource does not exist.');
    }

    public function gone(Request $request): Response
    {
        return $this->problem($request, Response::HTTP_GONE, 'Gone', 'The ActivityPub resource has been permanently removed.');
    }

    public function badRequest(Request $request, string $detail): Response
    {
        return $this->problem($request, Response::HTTP_BAD_REQUEST, 'Bad Request', $detail);
    }

    public function accepted(): Response
    {
        return new Response('', Response::HTTP_ACCEPTED, [
            'Cache-Control'          => 'no-store',
            'Content-Length'         => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function inboxError(Request $request, int $status, string $detail, ?int $retryAfter = null): Response
    {
        $title = match ($status) {
            Response::HTTP_BAD_REQUEST             => 'Bad Request',
            Response::HTTP_UNAUTHORIZED            => 'Unauthorized',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'Payload Too Large',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE  => 'Unsupported Media Type',
            Response::HTTP_TOO_MANY_REQUESTS       => 'Too Many Requests',
            Response::HTTP_SERVICE_UNAVAILABLE     => 'Service Unavailable',
            default                                => 'Inbox Request Rejected',
        };
        $response = $this->problem($request, $status, $title, $detail);
        if ($retryAfter !== null && $retryAfter > 0) {
            $response->headers->set('Retry-After', (string)$retryAfter);
        }

        return $response;
    }

    private function notAcceptable(Request $request): Response
    {
        return $this->problem(
            $request,
            Response::HTTP_NOT_ACCEPTABLE,
            'Not Acceptable',
            'Request an ActivityStreams, JRD, or JSON representation supported by this endpoint.',
        );
    }

    private function problem(Request $request, int $status, string $title, string $detail): Response
    {
        return $this->encoded($request, $this->encode([
            'type'   => 'about:blank',
            'title'  => $title,
            'status' => $status,
            'detail' => $detail,
        ]), 'application/problem+json', $status, false);
    }

    private function encoded(
        Request $request,
        string  $body,
        string  $mediaType,
        int     $status,
        bool    $cacheable = true,
    ): Response {
        $response = new Response($body, $status, [
            'Content-Type'                 => $mediaType,
            'Content-Length'               => (string)\strlen($body),
            'Cache-Control'                => $cacheable
                ? 'public, max-age=60, stale-while-revalidate=300'
                : 'no-store',
            'Vary'                         => 'Accept',
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Access-Control-Allow-Origin'  => '*',
        ]);
        if ($cacheable) {
            $response->setEtag(hash('sha256', $body));
            $response->isNotModified($request);
        }

        if ($request->isMethod(Request::METHOD_HEAD)) {
            $response->setContent('');
        }

        return $response;
    }

    /** @param array<string, mixed> $document */
    private function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param list<string> $mediaTypes */
    private function accepts(Request $request, array $mediaTypes): bool
    {
        $header = $request->headers->get('Accept');
        if ($header === null || trim($header) === '') {
            return true;
        }

        $accept = AcceptHeader::fromString($header);
        foreach ($mediaTypes as $mediaType) {
            $item = $accept->get($mediaType);
            if ($item instanceof \Symfony\Component\HttpFoundation\AcceptHeaderItem && $item->getQuality() > 0.0) {
                return true;
            }
        }

        return false;
    }
}
