<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\SyntaxHighlighting;

use Codeception\Test\Unit;
use Register\Module\SyntaxHighlighting\Module;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Framework\Container;
use S2\Cms\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ModuleTest extends Unit
{
    public function testAddsOnlyTheSmallLocalLoaderToEveryPage(): void
    {
        $container       = new Container(['base_path' => '/register/']);
        $eventDispatcher = new EventDispatcher();
        (new Module())->registerListeners($eventDispatcher, $container);

        $assetPack = new AssetPack('/tmp');
        $eventDispatcher->dispatch(new TemplateAssetEvent($assetPack));

        self::assertSame('', $assetPack->getStyles('', null));
        self::assertSame(
            '<script src="/register/_assets/register/syntax-highlighting/loader.js" defer></script>',
            $assetPack->getScripts('', null),
        );
        self::assertStringNotContainsString('http', $assetPack->getScripts('', null));
    }

    public function testLocalDistributionContainsThePinnedBuildAndItsLicense(): void
    {
        $assetDirectory = '_assets/register/syntax-highlighting';

        self::assertFileExists($assetDirectory . '/loader.js');
        self::assertFileExists($assetDirectory . '/theme.css');
        self::assertFileExists($assetDirectory . '/vendor/highlight.js/highlight.min.js');
        self::assertFileExists($assetDirectory . '/vendor/highlight.js/LICENSE');
        self::assertFileExists($assetDirectory . '/vendor/highlight.js/README.md');
    }
}
