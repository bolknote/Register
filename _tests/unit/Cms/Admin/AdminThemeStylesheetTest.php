<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\Cms\Admin\AdminThemeStylesheet;
use S2\Cms\Config\DynamicConfigProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminThemeStylesheetTest extends Unit
{
    public function testRendersStoredColorAsANonCacheableSameOriginStylesheet(): void
    {
        $stylesheet = $this->stylesheet('#A1B2C3');
        $request = Request::create('https://example.test/_admin/index.php?action=theme-stylesheet');

        self::assertTrue($stylesheet->supports($request));

        $response = $stylesheet->handle($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(":root {\n    --page-secondary-background: #a1b2c3;\n}\n", $response->getContent());
        self::assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function testAllowsAValidatedColorForTheUnsavedPreview(): void
    {
        $stylesheet = $this->stylesheet('#eeeeee');
        $request = Request::create(
            'https://example.test/_admin/index.php',
            Request::METHOD_GET,
            ['action' => AdminThemeStylesheet::ACTION, 'color' => '#F5E6E6'],
        );

        self::assertStringContainsString('#f5e6e6', (string)$stylesheet->handle($request)->getContent());
    }

    public function testRejectsInvalidPreviewColorAndUnsafeMethods(): void
    {
        $stylesheet = $this->stylesheet('#eeeeee');
        $invalidColor = Request::create(
            'https://example.test/_admin/index.php',
            Request::METHOD_GET,
            ['action' => AdminThemeStylesheet::ACTION, 'color' => 'red;body{display:none}'],
        );
        $post = Request::create(
            'https://example.test/_admin/index.php',
            Request::METHOD_POST,
            ['action' => AdminThemeStylesheet::ACTION],
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $stylesheet->handle($invalidColor)->getStatusCode());
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $stylesheet->handle($post)->getStatusCode());
        self::assertSame('GET, HEAD', $stylesheet->handle($post)->headers->get('Allow'));
    }

    public function testFallsBackWhenTheStoredColorIsInvalid(): void
    {
        $response = $this->stylesheet('</style><script>alert(1)</script>')->handle(
            Request::create('https://example.test/_admin/index.php?action=theme-stylesheet'),
        );

        self::assertStringContainsString('#eeeeee', (string)$response->getContent());
        self::assertStringNotContainsString('<script', (string)$response->getContent());
    }

    private function stylesheet(string $color): AdminThemeStylesheet
    {
        $configProvider = new DynamicConfigProvider();
        $reflection = new \ReflectionClass($configProvider);
        $reflection->getProperty('params')->setValue($configProvider, ['S2_ADMIN_COLOR' => $color]);

        return new AdminThemeStylesheet($configProvider);
    }
}
