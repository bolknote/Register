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
        self::assertFileExists($assetDirectory . '/vendor/highlight.js/languages.json');
    }

    public function testPinnedBuildManifestMatchesTheBundleAndRequiredLanguages(): void
    {
        $vendorDirectory = '_assets/register/syntax-highlighting/vendor/highlight.js';

        /** @var array{version: string, sha256: string, languages: list<string>} $manifest */
        $manifest = json_decode(
            (string)file_get_contents($vendorDirectory . '/languages.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('11.11.2', $manifest['version']);
        self::assertSame(hash_file('sha256', $vendorDirectory . '/highlight.min.js'), $manifest['sha256']);
        self::assertCount(46, $manifest['languages']);

        $importLanguages = [
            'applescript',
            'bash',
            'basic',
            'c',
            'cpp',
            'css',
            'delphi',
            'dos',
            'fortran',
            'go',
            'javascript',
            'lisp',
            'lua',
            'perl',
            'php',
            'plaintext',
            'python',
            'r',
            'rust',
            'sql',
            'vbscript',
            'x86asm',
            'xml',
        ];
        foreach ([...$importLanguages, 'brainfuck', 'powershell'] as $language) {
            self::assertContains($language, $manifest['languages']);
        }
    }
}
