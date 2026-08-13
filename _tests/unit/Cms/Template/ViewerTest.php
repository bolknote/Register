<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Search\Module as SearchModule;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Template\ModuleResourceLocator;
use S2\Cms\Template\Viewer;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ViewerTest extends Unit
{
    public function testBuiltInModuleViewsAreLoadedFromModuleResources(): void
    {
        $viewer = new Viewer(
            self::createStub(TranslatorInterface::class),
            new UrlBuilder('', '', ''),
            \dirname(__DIR__, 4) . '/',
            $this->styleProxy(),
            false,
        );

        self::assertStringContainsString(
            '<form class="search-form"',
            $viewer->render('search', ['query' => '', 'action' => '/search'], SearchModule::class),
        );
    }

    public function testBuiltInModulePageTemplatesAreLocatedInModuleResources(): void
    {
        $template = ModuleResourceLocator::templates(
            \dirname(__DIR__, 4) . '/',
            BlogModule::class,
        ) . 'blog.php';

        self::assertFileExists($template);
        self::assertStringContainsString('<!DOCTYPE html>', (string)file_get_contents($template));
    }

    public function testRejectsInvalidOptionalModuleIdentifier(): void
    {
        $viewer = new Viewer(
            self::createStub(TranslatorInterface::class),
            new UrlBuilder('', '', ''),
            \dirname(__DIR__, 4) . '/',
            $this->styleProxy(),
            false,
        );

        $this->expectException(\InvalidArgumentException::class);
        $viewer->render('search', [], '../outside');
    }

    private function styleProxy(): \S2\Cms\Config\StringProxy
    {
        $provider = new DynamicConfigProvider();
        $reflection = new \ReflectionClass($provider);
        $reflection->getProperty('params')->setValue($provider, ['S2_STYLE' => 'register']);

        return $provider->getStringProxy('S2_STYLE');
    }
}
