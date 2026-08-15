<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Http\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CspViolationReportCest
{
    private string $reportFile;

    public function _before(): void
    {
        $this->reportFile = dirname(__DIR__, 2) . '/_cache/test/csp-violations.jsonl';
        s2_call_without_warnings(fn(): bool => unlink($this->reportFile));
    }

    public function _after(): void
    {
        s2_call_without_warnings(fn(): bool => unlink($this->reportFile));
    }

    public function reportEndpointIsPostOnlyAndStoresSanitizedTelemetry(\IntegrationTester $I): void
    {
        $application = $I->createApplication();

        $getResponse = $application->handle(Request::create(
            'https://localhost' . ContentSecurityPolicy::REPORT_PATH,
        ));
        $I->assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $getResponse->getStatusCode());

        $payload = json_encode(['csp-report' => [
            'document-uri'        => 'https://localhost/admin?session=must-not-be-logged',
            'effective-directive' => 'style-src-attr',
            'blocked-uri'         => 'inline',
            'sample'              => 'apiKey=must-not-be-logged',
            'disposition'         => 'report',
        ]], JSON_THROW_ON_ERROR);
        $postResponse = $application->handle(Request::create(
            'https://localhost' . ContentSecurityPolicy::REPORT_PATH,
            Request::METHOD_POST,
            server: [
                'CONTENT_TYPE'   => 'application/csp-report',
                'REMOTE_ADDR'    => '192.0.2.200',
                'HTTP_USER_AGENT' => 'Integration CSP browser',
            ],
            content: $payload,
        ));

        $I->assertSame(Response::HTTP_NO_CONTENT, $postResponse->getStatusCode());
        $contents = file_get_contents($this->reportFile);
        $I->assertIsString($contents);
        $I->assertStringContainsString('"effective_directive":"style-src-attr"', $contents);
        $I->assertStringContainsString('"blocked_resource":"inline"', $contents);
        $I->assertStringNotContainsString('must-not-be-logged', $contents);
        $I->assertStringNotContainsString('192.0.2.200', $contents);
        $I->assertStringNotContainsString('Integration CSP browser', $contents);
    }
}
