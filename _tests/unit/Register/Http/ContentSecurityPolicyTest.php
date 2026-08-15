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

        ContentSecurityPolicy::apply($response);

        self::assertSame(ContentSecurityPolicy::POLICY, $response->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertStringContainsString("script-src 'self'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("script-src-attr 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("base-uri 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("object-src 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", ContentSecurityPolicy::POLICY);
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
