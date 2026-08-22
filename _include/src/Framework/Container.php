<?php
/**
 * Simple DI container.
 *
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Register\Core\Framework\Exception\DecoratedServiceNotFoundException;
use Register\Core\Framework\Exception\ParameterNotFoundException;
use Register\Core\Framework\Exception\ServiceAlreadyDefinedException;
use Register\Core\Framework\Exception\ServiceNotFoundException;

class Container implements ContainerInterface
{
    /**
     * @var array<mixed>
     */
    private array $bindings = [];

    /**
     * @var array<mixed>
     */
    private array $instances = [];

    /**
     * @var array<mixed>
     */
    private array $idsByTag = [];

    /**
     * @param array<mixed> $parameters
     */
    public function __construct(private readonly array $parameters)
    {
    }

    /**
     * @param array<mixed> $tags
     */
    public function set(string $id, callable|object $factory, array $tags = []): void
    {
        if (isset($this->bindings[$id])) {
            if ($this->bindings[$id] instanceof ServiceDecorator) {
                $this->bindings[$id]->setFactory($factory);
                return;
            }

            throw new ServiceAlreadyDefinedException(\sprintf('Entity "%s" already exists in container.', $id));
        }

        $this->bindings[$id] = $factory;
        if (!\is_callable($factory)) {
            $this->instances[$id] = $factory;
        }

        foreach ($tags as $tag) {
            $this->idsByTag[$tag][] = $id;
        }
    }

    public function decorate(string $id, callable $decorator): void
    {
        $this->bindings[$id] = new ServiceDecorator($this->bindings[$id] ?? null, $decorator, $this);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    #[\Override]
    public function get(string $id): mixed
    {
        if (!isset($this->bindings[$id])) {
            throw new ServiceNotFoundException(\sprintf('Entity "%s" not found in container.', $id));
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $factory = $this->bindings[$id];

        try {
            return $this->instances[$id] = $factory($this);
        } catch (DecoratedServiceNotFoundException $decoratedServiceNotFoundException) {
            throw new ServiceNotFoundException(\sprintf('Service "%s" was decorated, but original service was not defined in container.', $id), 0, $decoratedServiceNotFoundException);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T|null : mixed)
     */
    public function getIfDefined(string $id): mixed
    {
        return $this->has($id) ? $this->get($id) : null;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $tag
     * @return ($tag is class-string<T> ? list<T> : list<mixed>)
     */
    public function getByTag(string $tag): array
    {
        try {
            return array_values(array_map($this->get(...), $this->idsByTag[$tag] ?? []));
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface  $e) {
            throw new \LogicException('Impossible exception occurred', 0, $e);
        }
    }

    /**
     * @template T of object
     * @param class-string<T>|string $tag
     * @return ($tag is class-string<T> ? list<T> : list<mixed>)
     */
    public function getByTagIfInstantiated(string $tag): array
    {
        $services = array_map($this->getIfInstantiated(...), $this->idsByTag[$tag] ?? []);

        return array_values(array_filter($services, static fn(mixed $service): bool => $service !== null));
    }

    public function clear(string $id): void
    {
        if (!isset($this->bindings[$id])) {
            throw new ServiceNotFoundException(\sprintf('Entity "%s" not found in container.', $id));
        }

        unset($this->instances[$id]);
    }

    /**
     * @return array<mixed>
     */
    public function clearByTag(string $tag): array
    {
        try {
            return array_map($this->clear(...), $this->idsByTag[$tag] ?? []);
        } catch (NotFoundExceptionInterface $notFoundException) {
            throw new \LogicException('Impossible exception occurred', 0, $notFoundException);
        }
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T|null : mixed)
     */
    public function getIfInstantiated(string $id): mixed
    {
        return $this->instances[$id] ?? null;
    }

    #[\Override]
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function getParameter(string $name): mixed
    {
        if (!\array_key_exists($name, $this->parameters)) {
            throw new ParameterNotFoundException(\sprintf('Unknown parameter "%s" has been requested from container. Either define one or fix its name.', $name));
        }

        if (!isset($this->parameters[$name])) {
            throw new ParameterNotFoundException(\sprintf('Parameter "%s" is initialized with null value in container.', $name));
        }

        return $this->parameters[$name];
    }

    public function getStringParameter(string $name): string
    {
        $value = $this->getParameter($name);
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be a string.', $name));
        }

        return $value;
    }

    public function getBoolParameter(string $name): bool
    {
        $value = $this->getParameter($name);
        if (!\is_bool($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be a boolean.', $name));
        }

        return $value;
    }

    public function getIntParameter(string $name): int
    {
        $value = $this->getParameter($name);
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be an integer.', $name));
        }

        return $value;
    }

    public function getFloatParameter(string $name): float
    {
        $value = $this->getParameter($name);
        if (!\is_float($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be a float.', $name));
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getArrayParameter(string $name): array
    {
        $value = $this->getParameter($name);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be an array.', $name));
        }

        return $value;
    }

    public function getNullableParameter(string $name): mixed
    {
        if (!\array_key_exists($name, $this->parameters)) {
            throw new ParameterNotFoundException(\sprintf('Unknown parameter "%s" has been requested from container. Either define one or fix its name.', $name));
        }

        return $this->parameters[$name];
    }

    public function getNullableStringParameter(string $name): ?string
    {
        $value = $this->getNullableParameter($name);
        if ($value !== null && !\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Parameter "%s" must be a string or null.', $name));
        }

        return $value;
    }
}
