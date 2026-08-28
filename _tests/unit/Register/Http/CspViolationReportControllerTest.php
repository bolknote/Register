<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Core\Http\ContentSecurityPolicy;
use Register\Core\Http\CspViolationReportController;
use Register\Core\Http\CspViolationReporter;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CspViolationReportControllerTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_csp_controller_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function _after(): void
    {
        $file = $this->directory . '/csp-violations.jsonl';
        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAcceptsLegacyAndReportingApiPayloads(): void
    {
        $legacy = $this->controller()->handle($this->request(
            'application/csp-report',
            ['csp-report' => ['effective-directive' => 'style-src-elem', 'blocked-uri' => 'inline']],
        ));
        self::assertSame(Response::HTTP_NO_CONTENT, $legacy->getStatusCode());
        self::assertStringContainsString('no-store', $legacy->headers->get('Cache-Control') ?? '');

        $modern = $this->controller()->handle($this->request(
            'application/reports+json',
            [[
                'type' => 'csp-violation',
                'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'http://images.example/test.png'],
            ]],
        ));
        self::assertSame(Response::HTTP_NO_CONTENT, $modern->getStatusCode());

        $records = array_values(array_filter(
            explode("\n", $this->contents()),
            static fn(string $line): bool => $line !== '',
        ));
        self::assertCount(2, $records);
    }

    public function testRejectsWrongMethodTypeMalformedAndOversizedRequests(): void
    {
        $controller = $this->controller();

        $get = $controller->handle(Request::create(ContentSecurityPolicy::REPORT_PATH));
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $get->getStatusCode());
        self::assertSame(Request::METHOD_POST, $get->headers->get('Allow'));

        $wrongType = $controller->handle(Request::create(
            ContentSecurityPolicy::REPORT_PATH,
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        ));
        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $wrongType->getStatusCode());

        $malformed = $controller->handle(Request::create(
            ContentSecurityPolicy::REPORT_PATH,
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: '{',
        ));
        self::assertSame(Response::HTTP_BAD_REQUEST, $malformed->getStatusCode());

        $oversized = $controller->handle(Request::create(
            ContentSecurityPolicy::REPORT_PATH,
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: str_repeat('x', 16_385),
        ));
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $oversized->getStatusCode());
    }

    /** @param array<mixed> $payload */
    private function request(string $contentType, array $payload): Request
    {
        return Request::create(
            ContentSecurityPolicy::REPORT_PATH,
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => $contentType],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function controller(): CspViolationReportController
    {
        return new CspViolationReportController(new CspViolationReporter(
            $this->directory . '/csp-violations.jsonl',
            new SpamIdentityHasher(str_repeat('a', 32)),
        ));
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->directory . '/csp-violations.jsonl');
        self::assertIsString($contents);

        return $contents;
    }
}
