<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use S2\Cms\Asset\AssetPack;

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

        self::assertInstanceOf(AssetPack::class, $register);
        self::assertInstanceOf(AssetPack::class, $oldschool);
        self::assertInstanceOf(AssetPack::class, $pixelForest);
        self::assertSame(AssetPack::COLOR_SCHEME_SYSTEM, $register->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_LIGHT, $oldschool->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_DARK, $pixelForest->getColorScheme());
    }
}
