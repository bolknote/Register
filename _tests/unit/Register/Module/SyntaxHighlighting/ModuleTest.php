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
use Register\Core\Asset\AssetPack;
use Register\Core\Framework\Container;
use Register\Core\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ModuleTest extends Unit
{
    public function testAddsOnlyTheSmallLocalLoaderToEveryPage(): void
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
        $loaderModifiedAt = filemtime($publicRoot . '_assets/register/syntax-highlighting/loader.js');
        self::assertIsInt($loaderModifiedAt);

        $styles = $assetPack->getStyles('', null);
        self::assertStringContainsString('<meta name="register-syntax-highlighting"', $styles);
        self::assertStringContainsString('data-script-url="/register/_assets/register/syntax-highlighting/vendor/highlight.js/highlight.min.js?v=', $styles);
        self::assertStringContainsString('data-style-url="/register/_assets/register/syntax-highlighting/theme.css?v=', $styles);
        self::assertStringNotContainsString('<link', $styles);
        self::assertSame(
            '<script src="/register/_assets/register/syntax-highlighting/loader.js?v=' . $loaderModifiedAt . '" defer></script>',
            $assetPack->getScripts('', null),
        );
        self::assertStringNotContainsString('http', $assetPack->getScripts('', null));
    }

    public function testProductionLazyAssetsUseTheirOwnBuildHashesUnderTheBasePath(): void
    {
        $root = sys_get_temp_dir() . '/register-syntax-assets-' . bin2hex(random_bytes(8));
        $paths = [
            '_assets/register/syntax-highlighting/loader.js',
            '_assets/register/syntax-highlighting/vendor/highlight.js/highlight.min.js',
            '_assets/register/syntax-highlighting/theme.css',
        ];
        $assets = [];
        try {
            foreach ($paths as $index => $path) {
                if (!is_dir(\dirname($root . '/' . $path))) {
                    mkdir(\dirname($root . '/' . $path), 0700, true);
                }

                file_put_contents($root . '/' . $path, 'fixture-' . $index);
                $assets[$path] = hash('sha256', 'fixture-' . $index);
            }

            mkdir($root . '/_include', 0700);
            file_put_contents($root . '/_include/asset-manifest.json', json_encode([
                'version' => 1, 'assets' => $assets,
            ], JSON_THROW_ON_ERROR));
            $container = new Container(['base_path' => '/blog/', 'public_root_dir' => $root]);
            $dispatcher = new EventDispatcher();
            (new Module())->registerListeners($dispatcher, $container);
            $pack = new AssetPack($root);
            $dispatcher->dispatch(new TemplateAssetEvent($pack));

            self::assertStringContainsString('/blog/' . $paths[1] . '.asset?v=' . $assets[$paths[1]], $pack->getStyles('', null));
            self::assertStringContainsString('/blog/' . $paths[2] . '.asset?v=' . $assets[$paths[2]], $pack->getStyles('', null));
            self::assertSame('<script src="/blog/' . $paths[0] . '.asset?v=' . $assets[$paths[0]] . '" defer></script>', $pack->getScripts('', null));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
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
