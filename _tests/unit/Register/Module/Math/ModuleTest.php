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
use Register\Core\Asset\AssetPack;
use Register\Core\Framework\Container;
use Register\Core\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ModuleTest extends Unit
{
    public function testUsesOnlyLocalAssetsUnderTheConfiguredBasePath(): void
    {
        $publicRoot      = dirname(__DIR__, 5) . '/';
        $container       = new Container([
            'base_path'      => '/register/',
            'public_root_dir' => $publicRoot,
        ]);
        $eventDispatcher = new EventDispatcher();
        (new Module())->registerListeners($eventDispatcher, $container);

        $assetPack = new AssetPack('/tmp');
        $eventDispatcher->dispatch(new TemplateAssetEvent($assetPack));
        $stylesheetModifiedAt = filemtime($publicRoot . '_assets/register/math/math.css');
        $loaderModifiedAt     = filemtime($publicRoot . '_assets/register/math/loader.js');
        self::assertIsInt($stylesheetModifiedAt);
        self::assertIsInt($loaderModifiedAt);

        self::assertSame(
            '<link rel="stylesheet" href="/register/_assets/register/math/math.css?v=' . $stylesheetModifiedAt . '">',
            $assetPack->getStyles('', null),
        );
        self::assertSame(
            '<script src="/register/_assets/register/math/loader.js?v=' . $loaderModifiedAt . '" defer></script>',
            $assetPack->getScripts('', null),
        );
        self::assertStringNotContainsString('http', $assetPack->getStyles('', null) . $assetPack->getScripts('', null));
    }
}
