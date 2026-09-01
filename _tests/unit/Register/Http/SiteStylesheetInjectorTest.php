<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Core\Asset\AssetPack;
use Register\Core\CmsExtension;
use Register\Core\Framework\Container;
use Register\Core\Http\SiteStylesheetInjector;
use Register\Core\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SiteStylesheetInjectorTest extends Unit
{
    private string $rootDir = '';

    #[\Override]
    protected function _before(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
    }

    public function testDoesNothingWithoutConfiguredStylesheets(): void
    {
        $html = '<html><head><title>Test</title></head><body><p>Text</p></body></html>';

        self::assertSame($html, (new SiteStylesheetInjector($this->rootDir, '/blog', []))->inject(
            $html,
            'register',
        ));
    }

    public function testCmsExtensionAcceptsParametersFromAnOlderWorkerDuringRollingDeployment(): void
    {
        $container = new Container([
            'trusted_proxies' => [],
            'public_root_dir' => $this->rootDir,
            'base_path'       => '',
        ]);

        (new CmsExtension())->buildContainer($container);

        $html = '<html><head></head><body></body></html>';
        self::assertSame($html, $container->get(SiteStylesheetInjector::class)->inject($html, 'register'));
    }

    public function testCmsExtensionVersionsItsContentSecurityStylesheet(): void
    {
        $container = new Container([
            'base_path'      => '/blog/',
            'public_root_dir' => $this->rootDir . '/',
        ]);
        $eventDispatcher = new EventDispatcher();
        (new CmsExtension())->registerListeners($eventDispatcher, $container);

        $assetPack = new AssetPack($this->rootDir);
        $eventDispatcher->dispatch(new TemplateAssetEvent($assetPack));

        $modifiedAt = filemtime($this->rootDir . '/_assets/register/content-security.css');
        self::assertIsInt($modifiedAt);
        self::assertSame(
            '<link rel="stylesheet" href="/blog/_assets/register/content-security.css?v=' . $modifiedAt . '">',
            $assetPack->getStyles('', null),
        );
    }

    public function testAddsAConfiguredRootRelativeStylesheet(): void
    {
        $result = (new SiteStylesheetInjector($this->rootDir, '/blog', [
            '/_assets/register/content-security.css',
        ]))->inject('<html><head></head><body></body></html>', 'register');

        self::assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="/blog/_assets/register/content-security\.css\?v=\d+">~',
            $result,
        );
    }

    public function testAppliesMarkerAndThemeConditionsWithoutKnowingTheirMeaning(): void
    {
        $injector = new SiteStylesheetInjector($this->rootDir, '', [
            [
                'href'    => '/_assets/register/content-security.css',
                'markers' => ['content-owned-marker'],
                'themes'  => ['matching-theme'],
            ],
        ]);
        $html = '<html><head></head><body>content-owned-marker</body></html>';

        self::assertStringContainsString('content-security.css', $injector->inject($html, 'matching-theme'));
        self::assertSame($html, $injector->inject($html, 'other-theme'));
        self::assertSame(
            '<html><head></head><body></body></html>',
            $injector->inject('<html><head></head><body></body></html>', 'matching-theme'),
        );
    }

    public function testRejectsPathsOutsideThePublicRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SiteStylesheetInjector($this->rootDir, '', ['../private.css']);
    }

    public function testReportsAMissingConfiguredStylesheetOnlyWhenItsConditionMatches(): void
    {
        $injector = new SiteStylesheetInjector($this->rootDir, '', [[
            'href'    => '/missing.css',
            'markers' => ['matching-marker'],
        ]]);
        $html = '<html><head></head><body></body></html>';

        self::assertSame($html, $injector->inject($html, 'register'));

        $this->expectException(\LogicException::class);
        $injector->inject('<html><head></head><body>matching-marker</body></html>', 'register');
    }
}
