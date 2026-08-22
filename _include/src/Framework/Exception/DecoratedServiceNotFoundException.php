<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework\Exception;

use Psr\Container\ContainerExceptionInterface;

class DecoratedServiceNotFoundException extends \RuntimeException implements ContainerExceptionInterface
{

}
