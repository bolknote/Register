<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Config;

/**
 * Describes secrets owned by core configuration and optional extensions.
 *
 * Extension-private values intentionally use a reserved namespace so they remain readable while
 * their extension is disabled or its code is temporarily absent.
 */
final class DynamicSecretParameterRegistry
{
    public const string EXTENSION_PRIVATE_PREFIX = 'REGISTER_EXTENSION_';

    /** @var array<string, true> */
    private array $managed = [];

    /** @var array<string, true> */
    private array $hydrateOnly = [];

    /** @var array<string, true> */
    private array $extensionPrivate = [];

    /**
     * @param list<string> $managed
     * @param list<string> $hydrateOnly
     */
    public function __construct(array $managed, array $hydrateOnly = [])
    {
        foreach ($managed as $parameterName) {
            $this->register($this->managed, $parameterName);
        }

        foreach ($hydrateOnly as $parameterName) {
            if (isset($this->managed[$parameterName])) {
                throw new \InvalidArgumentException('A dynamic secret cannot be both managed and hydrate-only.');
            }

            $this->register($this->hydrateOnly, $parameterName);
        }

        if ($this->managed === []) {
            throw new \InvalidArgumentException('At least one managed dynamic secret must be configured.');
        }
    }

    public function registerExtensionPrivate(string $parameterName): void
    {
        if (!str_starts_with($parameterName, self::EXTENSION_PRIVATE_PREFIX)) {
            throw new \InvalidArgumentException('An extension-private secret must use the reserved namespace.');
        }

        if (isset($this->managed[$parameterName]) || isset($this->hydrateOnly[$parameterName])) {
            throw new \InvalidArgumentException('A configuration secret cannot also be extension-private.');
        }

        $this->register($this->extensionPrivate, $parameterName);
    }

    /** @return list<string> */
    public function managedNames(): array
    {
        return array_keys($this->managed);
    }

    /** @return list<string> */
    public function hydrateOnlyNames(): array
    {
        return array_keys($this->hydrateOnly);
    }

    public function isRegisteredExtensionPrivate(string $parameterName): bool
    {
        return isset($this->extensionPrivate[$parameterName]);
    }

    public function isAllowedInFile(string $parameterName): bool
    {
        return isset($this->managed[$parameterName])
            || isset($this->hydrateOnly[$parameterName])
            || isset($this->extensionPrivate[$parameterName])
            || preg_match('/^REGISTER_EXTENSION_[A-Z0-9_]{1,96}$/D', $parameterName) === 1;
    }

    /** @param array<string, true> $target */
    private function register(array &$target, string $parameterName): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $parameterName) !== 1) {
            throw new \InvalidArgumentException('A dynamic secret parameter name is invalid.');
        }

        $target[$parameterName] = true;
    }
}
