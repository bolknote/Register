<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Math;

use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ModuleInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

class AdminModule implements ModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, static function (CustomTemplateRendererEvent $event): void {
            $event->extraScripts[] = $event->basePath . '/_assets/register/math-preview.js';
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
    }
}
