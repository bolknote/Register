<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\AudioPlayer;

use Codeception\Test\Unit;
use Register\Module\AudioPlayer\Module;
use Register\Core\Asset\AssetPack;
use Register\Core\Framework\Container;
use Register\Core\Template\TemplateAssetEvent;
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
            '<script src="/register/_assets/register/audio-player/loader.js" defer></script>',
            $assetPack->getScripts('', null),
        );
        self::assertStringNotContainsString('http', $assetPack->getScripts('', null));
    }

    public function testDistributionContainsPlayerAndJoueleLicenseNotice(): void
    {
        $assetDirectory = '_assets/register/audio-player';

        self::assertFileExists($assetDirectory . '/loader.js');
        self::assertFileExists($assetDirectory . '/player.js');
        self::assertFileExists($assetDirectory . '/player.css');
        self::assertFileExists($assetDirectory . '/THIRD_PARTY_NOTICES.md');

        $notice = (string)file_get_contents($assetDirectory . '/THIRD_PARTY_NOTICES.md');
        self::assertStringContainsString('Copyright (c) 2015 Ilya Birman', $notice);
        self::assertStringContainsString('Copyright (c) 2015 Evgeniy Lazarev', $notice);
        self::assertStringContainsString('The MIT License (MIT)', $notice);
    }

    public function testPlayerUsesNativeAudioWithoutLegacyDependencies(): void
    {
        $loader = (string)file_get_contents('_assets/register/audio-player/loader.js');
        $player = (string)file_get_contents('_assets/register/audio-player/player.js');

        self::assertStringContainsString("audio[controls]", $loader);
        self::assertStringContainsString('state.audio.play()', $player);
        self::assertStringNotContainsString('jQuery', $loader . $player);
        self::assertStringNotContainsString('Howler', $loader . $player);
        self::assertStringNotContainsString('howler', $loader . $player);
    }

    public function testEditorInsertsNativeFallbackMarkupForAudioFiles(): void
    {
        $mediaManager = (string)file_get_contents('_admin/js/pictman.js');
        $editorEntry  = (string)file_get_contents('_admin/js/editor/entry.js');
        $editorForm   = (string)file_get_contents('_admin/js/editor/form.js');

        self::assertStringContainsString("['mp3', 'wav', 'ogg', 'flac']", $mediaManager);
        self::assertStringContainsString('parentWnd.ReturnAudio(filePath', $mediaManager);
        self::assertStringContainsString('window.ReturnAudio = ReturnAudio', $editorEntry);
        self::assertStringContainsString('<audio controls preload="metadata"', $editorForm);
    }
}
