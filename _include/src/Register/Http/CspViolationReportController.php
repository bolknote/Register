<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use S2\Cms\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CspViolationReportController implements ControllerInterface
{
    private const int MAX_BODY_BYTES = 16_384;

    private const int MAX_REPORTS_PER_REQUEST = 5;

    private const array CONTENT_TYPES = [
        'application/csp-report',
        'application/reports+json',
    ];

    public function __construct(private CspViolationReporter $reporter)
    {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->response(Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => Request::METHOD_POST]);
        }

        $contentTypeHeader = $request->headers->get('Content-Type') ?? '';
        $contentType       = strtolower(trim(explode(';', $contentTypeHeader, 2)[0]));
        if (!\in_array($contentType, self::CONTENT_TYPES, true)) {
            return $this->response(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $contentLength = trim($request->headers->get('Content-Length') ?? '');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int)$contentLength > self::MAX_BODY_BYTES) {
            return $this->response(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->response(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->response(Response::HTTP_BAD_REQUEST);
        }

        $reports = $this->extractReports($payload, $contentType);
        if ($reports === []) {
            return $this->response(Response::HTTP_BAD_REQUEST);
        }

        foreach (array_slice($reports, 0, self::MAX_REPORTS_PER_REQUEST) as $report) {
            $this->reporter->record($request, $report);
        }

        return $this->response(Response::HTTP_NO_CONTENT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractReports(mixed $payload, string $contentType): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        if ($contentType === 'application/csp-report') {
            $report = $payload['csp-report'] ?? null;

            return \is_array($report) && !array_is_list($report) ? [$report] : [];
        }

        if (!array_is_list($payload)) {
            return [];
        }

        $reports = [];
        foreach ($payload as $item) {
            if (!\is_array($item) || ($item['type'] ?? null) !== 'csp-violation') {
                continue;
            }

            $report = $item['body'] ?? null;
            if (\is_array($report) && !array_is_list($report)) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /** @param array<string, string> $headers */
    private function response(int $status, array $headers = []): Response
    {
        return new Response('', $status, [
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }
}
