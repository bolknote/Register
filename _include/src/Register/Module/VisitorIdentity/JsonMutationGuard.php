<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps cookie-backed JSON mutations same-origin without a per-page CSRF token. */
final class JsonMutationGuard
{
    public function violation(Request $request): ?JsonResponse
    {
        $contentType = strtolower($request->headers->get('Content-Type', '') ?? '');
        if (!str_starts_with($contentType, 'application/json')) {
            return new JsonResponse(
                ['success' => false, 'message' => 'A JSON request is required.'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $fetchSite = strtolower($request->headers->get('Sec-Fetch-Site', '') ?? '');
        if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
            return new JsonResponse(
                ['success' => false, 'message' => 'A same-origin request is required.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $origin = rtrim($request->headers->get('Origin', '') ?? '', '/');
        if ($origin !== '' && !hash_equals(strtolower($request->getSchemeAndHttpHost()), strtolower($origin))) {
            return new JsonResponse(
                ['success' => false, 'message' => 'The request origin is not allowed.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }
}
