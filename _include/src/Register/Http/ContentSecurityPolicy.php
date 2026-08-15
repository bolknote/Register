<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use Symfony\Component\HttpFoundation\Response;

final class ContentSecurityPolicy
{
    public const string HEADER_NAME = 'Content-Security-Policy';

    public const string POLICY = "default-src 'self'; "
        . "base-uri 'none'; "
        . "connect-src 'self'; "
        . "font-src 'self' data:; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "frame-src 'self' blob: https:; "
        . "img-src 'self' data: blob: http: https:; "
        . "media-src 'self' blob: http: https:; "
        . "object-src 'none'; "
        . "script-src 'self'; "
        . "script-src-attr 'none'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "worker-src 'self' blob:";

    public static function apply(Response $response): void
    {
        $response->headers->remove('X-Powered-By');
        $response->headers->set(self::HEADER_NAME, self::POLICY);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public static function applyToAdmin(Response $response): void
    {
        self::apply($response);
        $response->headers->set('Cache-Control', 'no-store, private');
    }

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header(self::HEADER_NAME . ': ' . self::POLICY);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}
