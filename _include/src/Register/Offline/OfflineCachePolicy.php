<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Offline;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marks only anonymous public HTML responses as safe for the browser's private offline cache. */
final class OfflineCachePolicy
{
    private const array INITIAL_SEED_EXCLUDED_PATHS = [
        '/comment_sent',
        '/comment_unsubscribe',
    ];

    public const string HEADER_NAME = 'X-Register-Offline-Cache';

    public const string HEADER_VALUE = 'public';

    public static function allowsInitialSeed(Request $request, bool $authenticated): bool
    {
        return !$authenticated
            && $request->isMethod(Request::METHOD_GET)
            && ($request->getQueryString() === null || $request->getQueryString() === '')
            && !\in_array($request->getPathInfo(), self::INITIAL_SEED_EXCLUDED_PATHS, true);
    }

    public static function apply(Request $request, Response $response, bool $authenticated): void
    {
        if (
            $authenticated
            || !$request->isMethod(Request::METHOD_GET)
            || $response->getStatusCode() !== Response::HTTP_OK
            || !str_starts_with(strtolower((string)$response->headers->get('Content-Type')), 'text/html')
            || str_contains(strtolower((string)$response->headers->get('Cache-Control')), 'no-store')
        ) {
            $response->headers->remove(self::HEADER_NAME);
            return;
        }

        $response->headers->set(self::HEADER_NAME, self::HEADER_VALUE);
    }
}
