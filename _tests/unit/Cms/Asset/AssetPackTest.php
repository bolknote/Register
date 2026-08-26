<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use Register\Core\Asset\AssetPack;

final class AssetPackTest extends Unit
{
    public function testColorSchemeIsDeclaredOnceAndCanBeReadByOtherInterfaces(): void
    {
        $assetPack = (new AssetPack('/tmp'))
            ->addMeta('<meta name="viewport" content="width=device-width">')
            ->setColorScheme(AssetPack::COLOR_SCHEME_LIGHT)
            ->setColorScheme(AssetPack::COLOR_SCHEME_DARK);

        self::assertSame(AssetPack::COLOR_SCHEME_DARK, $assetPack->getColorScheme());
        self::assertSame(
            "<meta name=\"viewport\" content=\"width=device-width\">\n"
            . '<meta name="color-scheme" content="dark">',
            $assetPack->getStyles('', null),
        );
    }

    public function testUnspecifiedColorSchemeUsesTheSystemWithoutAddingMarkup(): void
    {
        $assetPack = new AssetPack('/tmp');

        self::assertSame(AssetPack::COLOR_SCHEME_SYSTEM, $assetPack->getColorScheme());
        self::assertSame('', $assetPack->getStyles('', null));
    }

    public function testRejectsUnsupportedColorScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AssetPack('/tmp'))->setColorScheme('sepia');
    }

    public function testBuiltInStylesExposeTheirColorScheme(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $register = require $rootDir . '_styles/register/register.php';
        $oldschool = require $rootDir . '_styles/oldschool/oldschool.php';
        $pixelForest = require $rootDir . '_styles/pixel-forest/pixel-forest.php';
        $systemOne = require $rootDir . '_styles/system-1/system-1.php';

        self::assertInstanceOf(AssetPack::class, $register);
        self::assertInstanceOf(AssetPack::class, $oldschool);
        self::assertInstanceOf(AssetPack::class, $pixelForest);
        self::assertInstanceOf(AssetPack::class, $systemOne);
        self::assertSame(AssetPack::COLOR_SCHEME_SYSTEM, $register->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_LIGHT, $oldschool->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_DARK, $pixelForest->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_LIGHT, $systemOne->getColorScheme());
    }

    public function testBuiltInStylesLocalizeTimesBeforeRenderingTheBody(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';

        foreach (['register', 'oldschool', 'pixel-forest', 'system-1'] as $style) {
            /** @var AssetPack $assetPack */
            $assetPack = require $rootDir . '_styles/' . $style . '/' . $style . '.php';
            $markup    = $assetPack->getStyles('/_styles/' . $style . '/', null);
            $cssPath   = '/_styles/' . $style . '/../../_assets/register/local-time.css?v='
                . (string)\filemtime($rootDir . '_assets/register/local-time.css');
            $jsPath    = '/_styles/' . $style . '/../../_assets/register/local-time.js?v='
                . (string)\filemtime($rootDir . '_assets/register/local-time.js');

            self::assertStringContainsString('<link rel="stylesheet" href="' . $cssPath . '">', $markup);
            self::assertStringContainsString('<script src="' . $jsPath . '"></script>', $markup);
            self::assertStringNotContainsString('<script src="' . $jsPath . '" defer></script>', $markup);
        }
    }

    public function testDynamicFragmentsLocalizeTimesBeforeTheyAreInserted(): void
    {
        $assetRoot = \dirname(__DIR__, 4) . '/_assets/register/';

        foreach (['partial-navigation.js', 'live-updates.js'] as $filename) {
            $script = file_get_contents($assetRoot . $filename);

            self::assertIsString($script);
            $localizePosition = strpos($script, 'localizeTimesBeforeInsertion(replacement);');
            $replacePosition  = strpos($script, 'current.replaceWith(replacement);');
            self::assertIsInt($localizePosition);
            self::assertIsInt($replacePosition);
            self::assertLessThan($replacePosition, $localizePosition);
        }
    }

    public function testSystemOneThemeUsesGlobalGrayscaleAndMacOsArtwork(): void
    {
        $themeDir = \dirname(__DIR__, 4) . '/_styles/system-1/';
        $css = file_get_contents($themeDir . 'system-1.css');

        self::assertIsString($css);
        self::assertMatchesRegularExpression('/html\s*\{[^}]*filter:\s*grayscale\(1\);/s', $css);

        foreach (['finder.png', 'folder.png', 'document.png', 'trash.png'] as $asset) {
            self::assertFileExists($themeDir . $asset);
        }
    }

    public function testAlternativeThemesStyleWrappedPostCards(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';

        foreach (['oldschool', 'pixel-forest', 'system-1'] as $style) {
            $css = file_get_contents($rootDir . '_styles/' . $style . '/' . $style . '.css');

            self::assertIsString($css);
            self::assertStringContainsString('#content .post-card > .post.head', $css);
        }
    }

    public function testSystemOneChromeFollowsPartialNavigation(): void
    {
        $script = file_get_contents(\dirname(__DIR__, 4) . '/_styles/system-1/system-1.js');

        self::assertIsString($script);
        self::assertStringContainsString('.post-card > .post.head', $script);
        self::assertStringContainsString("'register:navigation-updated'", $script);
    }
}
