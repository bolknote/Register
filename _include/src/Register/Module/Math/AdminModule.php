<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Math;

use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\Framework\ListenerModuleInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AdminModule implements ListenerModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, static function (CustomTemplateRendererEvent $event): void {
            $event->extraStyles[]  = $event->basePath . '/_assets/register/math/math.css';
            $event->extraScripts[] = $event->basePath . '/_assets/register/math/loader.js';
        });
    }
}
