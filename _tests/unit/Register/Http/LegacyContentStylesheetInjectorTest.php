<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\LegacyContentStylesheetInjector;

final class LegacyContentStylesheetInjectorTest extends Unit
{
    private string $rootDir;

    private LegacyContentStylesheetInjector $injector;

    protected function _before(): void
    {
        $this->rootDir  = dirname(__DIR__, 4);
        $this->injector = new LegacyContentStylesheetInjector($this->rootDir, '/blog');
    }

    public function testLeavesOrdinaryEnginePageUntouched(): void
    {
        $html = '<html><head><title>Test</title></head><body><p>Text</p></body></html>';

        self::assertSame($html, $this->injector->inject($html, 'register'));
    }

    public function testAddsImportedBaseAndThemeStylesAfterEngineHeadAssets(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/engine.css"></head>'
            . '<body><details class="lj-cut"></details></body></html>';

        $result = $this->injector->inject($html, 'pixel-forest');

        self::assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="/blog/_assets/register/imported-content/site\.css\?v=\d+">~',
            $result,
        );
        self::assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="/blog/_styles/pixel-forest/imported-content\.css\?v=\d+">~',
            $result,
        );
        self::assertLessThan(
            strpos($result, '/blog/_styles/pixel-forest/imported-content.css'),
            strpos($result, '/blog/_assets/register/imported-content/site.css'),
        );
        self::assertStringNotContainsString('files-archive/site.css', $result);
    }

    public function testAddsArchiveStylesOnlyWhenArchiveMarkupIsRendered(): void
    {
        $result = $this->injector->inject(
            '<html><head></head><body><div class="files-archive-document"></div></body></html>',
            'register',
        );

        self::assertMatchesRegularExpression(
            '~href="/blog/_assets/register/files-archive/site\.css\?v=\d+"~',
            $result,
        );
        self::assertStringNotContainsString('imported-content/site.css', $result);
    }

    public function testAddsImportedStylesForConvertedE2EmojiRows(): void
    {
        $result = $this->injector->inject(
            '<html><head></head><body><p class="register-import-style-d105615e03df6e3f0b3b">Emoji</p></body></html>',
            'register',
        );

        self::assertMatchesRegularExpression(
            '~href="/blog/_assets/register/imported-content/site\.css\?v=\d+"~',
            $result,
        );
    }

    public function testAddsScopedLegacyPostStylesForExistingDatabaseRows(): void
    {
        $result = $this->injector->inject(
            '<html><head></head><body><article data-post-id="3050"></article></body></html>',
            'register',
        );

        self::assertMatchesRegularExpression(
            '~href="/blog/_assets/register/imported-content/legacy-posts\.css\?v=\d+"~',
            $result,
        );
    }

    public function testEngineStylesheetsDoNotContainImportedContentSelectors(): void
    {
        $engineStylesheets = [
            '_assets/register/content-security.css',
            '_styles/register/site.css',
            '_styles/pixel-forest/pixel-forest.css',
            '_styles/system-1/system-1.css',
        ];

        foreach ($engineStylesheets as $relativeFilename) {
            $content = file_get_contents($this->rootDir . '/' . $relativeFilename);

            self::assertIsString($content);
            self::assertDoesNotMatchRegularExpression(
                '~(?:lj-|readme-|files-archive-|register-autolink|bolk-spine-local|register-import-style-d105615e03df6e3f0b3b)~',
                $content,
                $relativeFilename,
            );
        }
    }
}
