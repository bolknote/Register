<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaMigrator;
use Register\Url\IcuTransliterator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ModuleInterface;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers services owned by the Register product rather than the reusable S2 foundation.
 */
readonly class ProductModule implements ModuleInterface
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(BaseModuleRegistry::class, $this->baseModuleRegistry);
        $container->set(
            BaseModuleInstaller::class,
            fn(Container $container): BaseModuleInstaller => new BaseModuleInstaller(
                $container->get(BaseModuleRegistry::class),
            ),
        );
        $container->set(SchemaMigrator::class, fn(Container $container): SchemaMigrator => new SchemaMigrator(
            $container->get(DbLayer::class),
            $container,
            $container->get(BaseModuleInstaller::class),
            $this->baseModuleRegistry,
        ));
        $container->set(SlugGenerator::class, static fn(Container $_container): SlugGenerator => new SlugGenerator(
            new PortableAsciiTransliterator(),
            IcuTransliterator::create(),
        ));
        $container->set(UniqueSlugGenerator::class, static fn(Container $container): UniqueSlugGenerator => new UniqueSlugGenerator(
            $container->get(SlugGenerator::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        unset($eventDispatcher, $container);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        unset($routes, $container);
    }
}
