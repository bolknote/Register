<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Math;

use Codeception\Test\Unit;
use Register\Module\Math\Module;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Framework\Container;
use S2\Cms\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ModuleTest extends Unit
{
    public function testUsesOnlyLocalAssetsUnderTheConfiguredBasePath(): void
    {
        $container       = new Container(['base_path' => '/register/']);
        $eventDispatcher = new EventDispatcher();
        (new Module())->registerListeners($eventDispatcher, $container);

        $assetPack = new AssetPack('/tmp');
        $eventDispatcher->dispatch(new TemplateAssetEvent($assetPack));

        self::assertSame(
            '<link rel="stylesheet" href="/register/_assets/register/math/math.css">',
            $assetPack->getStyles('', null),
        );
        self::assertSame(
            '<script src="/register/_assets/register/math/loader.js" defer></script>',
            $assetPack->getScripts('', null),
        );
        self::assertStringNotContainsString('http', $assetPack->getStyles('', null) . $assetPack->getScripts('', null));
    }
}
