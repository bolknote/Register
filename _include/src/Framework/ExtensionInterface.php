<?php
/**
 * Interface for extensions to be used in the Application class.
 * Extensions are responsible for building the container and registering listeners and routes.
 * Thus, they contain the logic of an application.
 *
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package S2
 */

declare(strict_types = 1);

namespace S2\Cms\Framework;

/**
 * Backward-compatible name for modules supplied by the S2 extension API.
 *
 * New Register code should depend on ModuleInterface. This interface remains while third-party
 * extensions migrate without requiring an all-at-once ecosystem break.
 */
interface ExtensionInterface extends ModuleInterface
{
}
