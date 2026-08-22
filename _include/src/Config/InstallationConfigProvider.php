<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;
use Psr\Cache\InvalidArgumentException;


class InstallationConfigProvider extends DynamicConfigProvider
{
    private ?\Closure $callback = null;

    public function setCallback(callable $callback): void
    {
        $this->callback = $callback(...);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[\Override]
    public function get(string $paramName): mixed
    {
        if (!$this->callback instanceof \Closure) {
            throw new \LogicException('Installation configuration callback has not been set.');
        }

        return ($this->callback)($paramName);
    }
}
