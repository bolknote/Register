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

    public const string REPORT_ONLY_HEADER_NAME = 'Content-Security-Policy-Report-Only';

    public const string REPORT_PATH = '/.well-known/csp-report';

    private const string REPORTING_GROUP = 'register-csp';

    private const string POLICY_PREFIX = "default-src 'self'; "
        . "base-uri 'none'; "
        . "connect-src 'self'; "
        . "font-src 'self' data:; "
        . "form-action 'self'; ";

    private const string ENFORCED_POLICY_SUFFIX = "frame-src 'self' blob: https:; "
        . "img-src 'self' data: blob: http: https:; "
        . "media-src 'self' blob: http: https:; "
        . "object-src 'none'; "
        . "script-src 'self'; "
        . "script-src-attr 'none'; "
        . "style-src 'self' 'unsafe-eval'; "
        . "style-src-attr 'none'; "
        . "worker-src 'self' blob:";

    private const string REPORT_ONLY_POLICY_SUFFIX = "frame-src 'self' blob: https:; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' blob: https:; "
        . "object-src 'none'; "
        . "script-src 'self'; "
        . "script-src-attr 'none'; "
        . "style-src 'self' 'unsafe-eval'; "
        . "style-src-attr 'none'; "
        . "worker-src 'self' blob:";

    public const string POLICY = self::POLICY_PREFIX
        . "frame-ancestors 'self'; "
        . self::ENFORCED_POLICY_SUFFIX;

    public const string ADMIN_POLICY = self::POLICY_PREFIX
        . "frame-ancestors 'none'; "
        . self::ENFORCED_POLICY_SUFFIX;

    public const string REPORT_ONLY_POLICY = self::POLICY_PREFIX
        . "frame-ancestors 'self'; "
        . self::REPORT_ONLY_POLICY_SUFFIX;

    public const string ADMIN_REPORT_ONLY_POLICY = self::POLICY_PREFIX
        . "frame-ancestors 'none'; "
        . self::REPORT_ONLY_POLICY_SUFFIX;

    public static function apply(Response $response, string $reportUri = ''): void
    {
        self::applyHeaders($response, self::POLICY, self::REPORT_ONLY_POLICY, $reportUri);
    }

    public static function applyToAdmin(Response $response, string $reportUri = ''): void
    {
        self::applyHeaders($response, self::ADMIN_POLICY, self::ADMIN_REPORT_ONLY_POLICY, $reportUri);
        $response->headers->set('Cache-Control', 'no-store, private');
    }

    public static function applyToEmbeddedAdmin(Response $response, string $reportUri = ''): void
    {
        self::applyHeaders($response, self::POLICY, self::REPORT_ONLY_POLICY, $reportUri);
        $response->headers->set('Cache-Control', 'no-store, private');
    }

    public static function send(string $reportUri = ''): void
    {
        if (headers_sent()) {
            return;
        }

        $reportingPolicy = self::reportingPolicy(self::REPORT_ONLY_POLICY, $reportUri);

        header_remove('X-Powered-By');
        header(self::HEADER_NAME . ': ' . self::POLICY);
        header(self::REPORT_ONLY_HEADER_NAME . ': ' . $reportingPolicy);
        if ($reportUri !== '') {
            header('Reporting-Endpoints: ' . self::REPORTING_GROUP . '="' . $reportUri . '"');
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    private static function applyHeaders(Response $response, string $policy, string $reportOnlyPolicy, string $reportUri): void
    {
        $reportingPolicy = self::reportingPolicy($reportOnlyPolicy, $reportUri);

        $response->headers->remove('X-Powered-By');
        $response->headers->set(self::HEADER_NAME, $policy);
        $response->headers->set(self::REPORT_ONLY_HEADER_NAME, $reportingPolicy);
        if ($reportUri === '') {
            $response->headers->remove('Reporting-Endpoints');
        } else {
            $response->headers->set('Reporting-Endpoints', self::REPORTING_GROUP . '="' . $reportUri . '"');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    private static function reportingPolicy(string $policy, string $reportUri): string
    {
        if ($reportUri === '') {
            return $policy;
        }

        if (
            !str_starts_with($reportUri, '/')
            || strlen($reportUri) > 2048
            || preg_match('/[\x00-\x20\x7f;,\'"\\\\]/', $reportUri) === 1
        ) {
            throw new \InvalidArgumentException('The CSP report URI must be a safe absolute-path reference.');
        }

        return $policy . '; report-uri ' . $reportUri . '; report-to ' . self::REPORTING_GROUP;
    }
}
