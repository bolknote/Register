<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Module\BaseModuleRegistry;
use Register\Runtime\AiModule;
use Register\Runtime\BackupModule;
use Register\Runtime\CommentModule;
use Register\Runtime\ContentModule;
use Register\Runtime\ImportModule;
use Register\Runtime\MaintenanceModule;
use Register\Runtime\PageModule;
use Register\Runtime\ProductWebModule;
use Register\Runtime\ProductSecretModule;
use Register\Runtime\PublicAuthModule;
use Register\Runtime\PublicPresentationModule;
use Register\Runtime\SchemaModule;
use Register\Runtime\UrlModule;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerAwareRoutingModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers services owned by the Register product rather than the reusable Register foundation.
 */
readonly class ProductModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, ContainerAwareRoutingModuleInterface
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(BaseModuleRegistry::class, $this->baseModuleRegistry);
        (new ProductSecretModule())->buildContainer($container);
        (new AiModule())->buildContainer($container);
        (new BackupModule())->buildContainer($container);
        (new ContentModule())->buildContainer($container);
        (new PageModule())->buildContainer($container);
        (new MaintenanceModule())->buildContainer($container);
        (new CommentModule())->buildContainer($container);
        (new ImportModule())->buildContainer($container);
        (new PublicAuthModule())->buildContainer($container);
        (new PublicPresentationModule())->buildContainer($container);
        (new SchemaModule())->buildContainer($container);
        (new UrlModule())->buildContainer($container);
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        (new ProductWebModule())->registerListeners($eventDispatcher, $container);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        (new ProductWebModule())->registerRoutes($routes, $container);
    }

}
