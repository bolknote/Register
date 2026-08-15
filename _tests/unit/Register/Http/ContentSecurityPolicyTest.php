<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Response;

final class ContentSecurityPolicyTest extends Unit
{
    public function testAppliesAnEnforcedScriptPolicy(): void
    {
        $response = new Response();

        ContentSecurityPolicy::apply($response, '/blog/.well-known/csp-report');

        self::assertSame(ContentSecurityPolicy::POLICY, $response->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertSame(
            ContentSecurityPolicy::REPORT_ONLY_POLICY
                . '; report-uri /blog/.well-known/csp-report; report-to register-csp',
            $response->headers->get(ContentSecurityPolicy::REPORT_ONLY_HEADER_NAME),
        );
        self::assertSame(
            'register-csp="/blog/.well-known/csp-report"',
            $response->headers->get('Reporting-Endpoints'),
        );
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertStringContainsString("script-src 'self'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("script-src-attr 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("base-uri 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("object-src 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("style-src 'self'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringContainsString("style-src-attr 'none'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringNotContainsString("'unsafe-inline'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringNotContainsString('http:', ContentSecurityPolicy::REPORT_ONLY_POLICY);
    }

    public function testAdminPagesCannotBeFramedExceptForExplicitEmbeddedEndpoints(): void
    {
        $adminResponse = new Response();
        ContentSecurityPolicy::applyToAdmin($adminResponse);

        self::assertSame(ContentSecurityPolicy::ADMIN_POLICY, $adminResponse->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertStringContainsString("frame-ancestors 'none'", ContentSecurityPolicy::ADMIN_POLICY);
        self::assertSame('no-store, private', $adminResponse->headers->get('Cache-Control'));

        $embeddedResponse = new Response();
        ContentSecurityPolicy::applyToEmbeddedAdmin($embeddedResponse);

        self::assertSame(ContentSecurityPolicy::POLICY, $embeddedResponse->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertStringContainsString("frame-ancestors 'self'", ContentSecurityPolicy::POLICY);
        self::assertSame('no-store, private', $embeddedResponse->headers->get('Cache-Control'));
    }

    public function testRejectsUnsafeReportUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSecurityPolicy::apply(new Response(), "/report\r\nX-Injected: true");
    }

    public function testServerRenderedSourcesDoNotRequireInlineJavaScript(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = [
            $root . '/_admin/install.php',
            $root . '/_admin/templates',
            $root . '/_include/installation_required.php',
            $root . '/_include/src',
            $root . '/_include/views',
            $root . '/_lang',
        ];

        foreach ($this->sourceFiles($paths) as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '~<script\b(?![^>]*\bsrc\s*=)[^>]*>~i',
                $source,
                $filename . ' contains an inline script.',
            );
            self::assertDoesNotMatchRegularExpression(
                '~\son[a-z]+\s*=~i',
                $source,
                $filename . ' contains an inline event handler.',
            );
            self::assertDoesNotMatchRegularExpression(
                '~javascript\s*:~i',
                $source,
                $filename . ' contains a javascript: URL.',
            );
        }
    }

    public function testStandalonePagesDoNotRequireInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $files = [
            $root . '/_admin/install.php',
            $root . '/_include/installation_required.php',
            $root . '/_include/src/Framework/Application.php',
            $root . '/_include/views/error.php',
        ];

        foreach ($files as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~<style\b~i', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source, $filename);
            self::assertStringContainsString('/_assets/register/standalone.css', $source, $filename);
        }

        self::assertFileExists($root . '/_assets/register/standalone.css');
    }

    public function testRecommendationMarkupDoesNotRequireInlineStyles(): void
    {
        $filename = dirname(__DIR__, 4) . '/_include/src/Register/Module/Search/resources/views/recommendations.php';
        $source = file_get_contents($filename);
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('~<style\b~i', $source);
        self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source);
    }

    public function testDebugMarkupDoesNotRequireInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            $root . '/_include/src/Template/Viewer.php',
            $root . '/_include/src/Template/HtmlTemplate.php',
            $root . '/_include/views/debug_queries.php',
        ] as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~<style\b~i', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source, $filename);
        }
    }

    public function testMigratedAdminInteractionsDoNotMutateInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            $root . '/_admin/js/structure.js',
            $root . '/_admin/js/pictman.js',
            $root . '/_admin/js/editor/images/pipeline.js',
        ] as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~\.style\b|\.css\s*\(~', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~setAttribute\s*\(\s*[\'\"]style[\'\"]~', $source, $filename);
        }
    }

    /**
     * @param list<string> $paths
     * @return \Generator<string>
     */
    private function sourceFiles(array $paths): \Generator
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                yield $path;
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['php', 'inc'], true)) {
                    continue;
                }

                yield $file->getPathname();
            }
        }
    }
}
