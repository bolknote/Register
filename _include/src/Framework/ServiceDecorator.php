<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

use Register\Core\Framework\Exception\DecoratedServiceNotFoundException;

class ServiceDecorator
{
    /**
     * @var callable|object|null
     */
    private $factory;

    /**
     * @var callable
     */
    private $decorator;

    public function __construct(
        callable|object|null       $factory,
        callable                   $decorator,
        private readonly Container $container
    ) {
        $this->factory   = $factory;
        $this->decorator = $decorator;
    }

    public function setFactory(callable|object $factory): void
    {
        $this->factory = $factory;
    }

    public function __invoke(): mixed
    {
        if ($this->factory === null) {
            throw new DecoratedServiceNotFoundException('Original factory is not set.');
        }

        return ($this->decorator)($this->container, $this->factory);
    }
}
