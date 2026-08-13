<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Framework;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** A module that subscribes to application events. */
interface ListenerModuleInterface extends ModuleInterface
{
    public function registerListeners(EventDispatcherInterface $eventDispatcher): void;
}
