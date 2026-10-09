<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use Register\Core\Asset\AssetMergeInterface;
use Register\Core\Asset\AssetPack;
use Register\Core\Asset\PrebuiltAssetManifest;
use Register\Core\Asset\PrebuiltAssetMerge;
use Register\Core\Asset\PublicAssetUrl;
use Symfony\Component\Filesystem\Filesystem;

final class PrebuiltAssetManifestTest extends Unit
{
    private string $root = '';

    #[\Override]
    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/register-prebuilt-assets-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/_include', 0700, true);
        mkdir($this->root . '/_assets', 0700);
        file_put_contents($this->root . '/_assets/style.css', '.icon:before{content:"★"}');
        $this->writeManifest(['_assets/style.css' => hash('sha256', '.icon:before{content:"★"}')]);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testPublicUrlsUseBuildHashesEvenWhenModificationTimesAreIdentical(): void
    {
        touch($this->root . '/_assets/style.css', 1_700_000_000);
        $url = new PublicAssetUrl($this->root, '/blog/');
        $hash = hash('sha256', '.icon:before{content:"★"}');

        self::assertSame('/blog/_assets/style.css.asset?v=' . $hash, $url->versioned('/_assets/style.css'));
    }

    public function testPrebuiltCssBypassesTheRuntimeMinifierAndKeepsRelativeUrlLocations(): void
    {
        $manifest = PrebuiltAssetManifest::fromPublicRoot($this->root, '/blog');
        self::assertInstanceOf(PrebuiltAssetManifest::class, $manifest);
        $fallback = new PrebuiltFallback();
        $merge = new PrebuiltAssetMerge($manifest, $fallback);
        $merge->concat($this->root . '/_assets/style.css');

        self::assertSame([$manifest->urlFor($this->root . '/_assets/style.css')], $merge->getMergedPaths());
        self::assertSame(0, $fallback->reads);
    }

    public function testUserInstalledAssetsStillUseTheRuntimeFallback(): void
    {
        $manifest = PrebuiltAssetManifest::fromPublicRoot($this->root, '');
        self::assertInstanceOf(PrebuiltAssetManifest::class, $manifest);
        $fallback = new PrebuiltFallback();
        $merge = new PrebuiltAssetMerge($manifest, $fallback);
        $merge->concat($this->root . '/_assets/style.css');
        $merge->concat('https://cdn.example.test/custom.css');

        self::assertSame(['/_cache/fallback.123.css.asset'], $merge->getMergedPaths());
        self::assertCount(2, $fallback->files);
        self::assertSame(1, $fallback->reads);
    }

    public function testManifestCannotExposePrivateImplementationFiles(): void
    {
        $this->writeManifest(['_assets/../_include/private.js' => str_repeat('a', 64)]);
        $this->expectException(\RuntimeException::class);
        PrebuiltAssetManifest::fromPublicRoot($this->root, '');
    }

    public function testBundlesRequireEveryMemberInOrderAndPreferTheCompleteVariant(): void
    {
        $assets = $this->writeBundleAssets('css');
        $this->writeManifest($assets, [
            ['path' => '_assets/public.css', 'files' => ['_assets/first.css', '_assets/second.css']],
            ['path' => '_assets/editor.css', 'files' => ['_assets/first.css', '_assets/second.css', '_assets/third.css']],
        ]);
        $manifest = PrebuiltAssetManifest::fromPublicRoot($this->root, '/blog');
        self::assertInstanceOf(PrebuiltAssetManifest::class, $manifest);
        $url = fn(string $name): string => (string)$manifest->urlFor($this->root . '/_assets/' . $name . '.css');

        self::assertSame([$url('public')], $manifest->bundleUrls([$url('first'), $url('second')]));
        self::assertSame([$url('editor')], $manifest->bundleUrls([$url('first'), $url('second'), $url('third')]));
        self::assertSame([$url('first')], $manifest->bundleUrls([$url('first')]));
        self::assertSame([$url('second'), $url('first')], $manifest->bundleUrls([$url('second'), $url('first')]));
        $withExtension = [$url('first'), 'https://example.test/custom.css', $url('second')];
        self::assertSame($withExtension, $manifest->bundleUrls($withExtension));
        $stale = [$url('first'), '/blog/_assets/second.css.asset?v=' . str_repeat('0', 64)];
        self::assertSame($stale, $manifest->bundleUrls($stale));
    }

    public function testAssetPackBundlesStylesAndDeferredScriptsWithoutCrossingAnAsyncBoundary(): void
    {
        $assets = $this->writeBundleAssets('css') + $this->writeBundleAssets('js');
        $this->writeManifest($assets, [
            ['path' => '_assets/public.css', 'files' => ['_assets/first.css', '_assets/second.css']],
            ['path' => '_assets/public.js', 'files' => ['_assets/first.js', '_assets/second.js']],
        ]);
        $manifest = PrebuiltAssetManifest::fromPublicRoot($this->root, '/blog');
        self::assertInstanceOf(PrebuiltAssetManifest::class, $manifest);
        $merge = new PrebuiltAssetMerge($manifest, new PrebuiltFallback());
        $url = fn(string $name): string => (string)$manifest->urlFor($this->root . '/_assets/' . $name);
        $pack = (new AssetPack($this->root))
            ->addCss($url('first.css'))->addCss($url('second.css'))
            ->addJs($url('first.js'), [AssetPack::OPTION_DEFER])
            ->addJs($url('second.js'), [AssetPack::OPTION_DEFER]);

        self::assertSame('<link rel="stylesheet" href="' . $url('public.css') . '">', $pack->getStyles('', $merge));
        self::assertSame('<script src="' . $url('public.js') . '" defer></script>', $pack->getScripts('', $merge));

        foreach ([[], [AssetPack::OPTION_ASYNC]] as $options) {
            $independent = (new AssetPack($this->root))
                ->addJs($url('first.js'), $options)
                ->addJs($url('second.js'), [AssetPack::OPTION_DEFER]);
            $scripts = $independent->getScripts('', $merge);
            self::assertStringContainsString($url('first.js'), $scripts);
            self::assertStringContainsString($url('second.js'), $scripts);
            self::assertStringNotContainsString($url('public.js'), $scripts);
        }

        $separated = (new AssetPack($this->root))
            ->addJs($url('first.js'), [AssetPack::OPTION_DEFER])
            ->addJs('https://example.test/async.js', [AssetPack::OPTION_ASYNC])
            ->addJs($url('second.js'), [AssetPack::OPTION_DEFER]);
        self::assertStringNotContainsString($url('public.js'), $separated->getScripts('', $merge));
    }

    public function testLegacyMergedStylesUseThePreparedBundleWithoutRuntimeMinification(): void
    {
        $assets = $this->writeBundleAssets('css');
        $this->writeManifest($assets, [
            ['path' => '_assets/public.css', 'files' => ['_assets/first.css', '_assets/second.css']],
        ]);
        $manifest = PrebuiltAssetManifest::fromPublicRoot($this->root, '');
        self::assertInstanceOf(PrebuiltAssetManifest::class, $manifest);
        $fallback = new PrebuiltFallback();
        $merge = new PrebuiltAssetMerge($manifest, $fallback);
        $merge->concat($this->root . '/_assets/first.css');
        $merge->concat($this->root . '/_assets/second.css');

        self::assertSame([$manifest->urlFor($this->root . '/_assets/public.css')], $merge->getMergedPaths());
        self::assertSame(0, $fallback->reads);
    }

    public function testBundleCannotIntroduceAnUnpublishedOrPrivateAsset(): void
    {
        $assets = $this->writeBundleAssets('css');
        $this->writeManifest($assets, [
            ['path' => '_assets/public.css', 'files' => ['_assets/first.css', '_include/private.css']],
        ]);
        $this->expectException(\RuntimeException::class);
        PrebuiltAssetManifest::fromPublicRoot($this->root, '');
    }

    /** @return array<string, string> */
    private function writeBundleAssets(string $extension): array
    {
        $assets = [];
        foreach (['first', 'second', 'third', 'public', 'editor'] as $name) {
            $path = '_assets/' . $name . '.' . $extension;
            file_put_contents($this->root . '/' . $path, $name);
            $assets[$path] = hash('sha256', $name);
        }

        return $assets;
    }

    /**
     * @param array<string, string> $assets
     * @param list<array{path: string, files: list<string>}> $bundles
     */
    private function writeManifest(array $assets, array $bundles = []): void
    {
        file_put_contents($this->root . '/' . PrebuiltAssetManifest::FILENAME, json_encode([
            'version' => 1,
            'assets' => $assets,
            'bundles' => $bundles,
        ], JSON_THROW_ON_ERROR));
    }
}

final class PrebuiltFallback implements AssetMergeInterface
{
    /** @var list<string> */
    public array $files = [];

    public int $reads = 0;

    #[\Override]
    public function concat(string $fileName): void
    {
        $this->files[] = $fileName;
    }

    #[\Override]
    public function getMergedPaths(): array
    {
        ++$this->reads;

        return ['/_cache/fallback.123.css.asset'];
    }
}
