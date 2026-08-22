<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Config;

use S2\Cms\Framework\Exception\ConfigurationException;

final readonly class DynamicSecretStore
{
    public const string DATABASE_PLACEHOLDER = '$register-private-secret:v1$';

    private DynamicSecretParameterRegistry $parameterRegistry;

    /**
     * @param list<string>|DynamicSecretParameterRegistry $parameterNames
     * @param list<string> $hydrateOnlyParameterNames Values migrated by an earlier release that
     *     must still be hydrated without migrating fresh database values.
     */
    public function __construct(
        private string $filename,
        array|DynamicSecretParameterRegistry $parameterNames,
        array          $hydrateOnlyParameterNames = [],
    ) {
        if (trim($filename) === '') {
            throw new \InvalidArgumentException('The dynamic secret filename cannot be empty.');
        }

        $this->parameterRegistry = $parameterNames instanceof DynamicSecretParameterRegistry
            ? $parameterNames
            : new DynamicSecretParameterRegistry($parameterNames, $hydrateOnlyParameterNames);
    }

    /**
     * Separates managed values from database configuration and persists them before the caller
     * replaces the database values with opaque placeholders.
     *
     * @param array<mixed> $databaseConfig
     * @return array{cache: array<mixed>, runtime: array<mixed>, database_updates: array<string, string>}
     */
    public function protect(array $databaseConfig): array
    {
        $storedSecrets   = $this->read();
        $updatedSecrets  = $storedSecrets;
        $cacheConfig     = $databaseConfig;
        $runtimeConfig   = $databaseConfig;
        $databaseUpdates = [];

        foreach ($this->parameterRegistry->managedNames() as $parameterName) {
            if (!array_key_exists($parameterName, $databaseConfig)) {
                unset($updatedSecrets[$parameterName]);
                continue;
            }

            $databaseValue = $databaseConfig[$parameterName];
            if (!\is_string($databaseValue)) {
                throw new ConfigurationException('A managed dynamic secret must be stored as text.');
            }

            if ($databaseValue === self::DATABASE_PLACEHOLDER) {
                if (!array_key_exists($parameterName, $storedSecrets)) {
                    throw new ConfigurationException(
                        \sprintf(
                            'The private dynamic-secret file is missing the value "%s" referenced by the database.',
                            $parameterName,
                        ),
                    );
                }

                $runtimeConfig[$parameterName] = $storedSecrets[$parameterName];
                continue;
            }

            if ($databaseValue === '') {
                unset($updatedSecrets[$parameterName]);
                continue;
            }

            $updatedSecrets[$parameterName]  = $databaseValue;
            $cacheConfig[$parameterName]     = self::DATABASE_PLACEHOLDER;
            $runtimeConfig[$parameterName]   = $databaseValue;
            $databaseUpdates[$parameterName] = self::DATABASE_PLACEHOLDER;
        }

        foreach ($this->parameterRegistry->hydrateOnlyNames() as $parameterName) {
            if (!array_key_exists($parameterName, $databaseConfig)) {
                unset($updatedSecrets[$parameterName]);
                continue;
            }

            $databaseValue = $databaseConfig[$parameterName];
            if (!\is_string($databaseValue)) {
                throw new ConfigurationException('A hydrate-only dynamic secret must be stored as text.');
            }

            if ($databaseValue === self::DATABASE_PLACEHOLDER) {
                if (!array_key_exists($parameterName, $storedSecrets)) {
                    throw new ConfigurationException(
                        \sprintf(
                            'The private dynamic-secret file is missing the value "%s" referenced by the database.',
                            $parameterName,
                        ),
                    );
                }

                $runtimeConfig[$parameterName] = $storedSecrets[$parameterName];
                continue;
            }

            unset($updatedSecrets[$parameterName]);
        }

        if ($updatedSecrets !== $storedSecrets) {
            $this->write($updatedSecrets);
        }

        return [
            'cache'            => $cacheConfig,
            'runtime'          => $runtimeConfig,
            'database_updates' => $databaseUpdates,
        ];
    }

    /** @param array<mixed> $cachedConfig */
    public function requiresRegeneration(array $cachedConfig): bool
    {
        $storedSecrets = $this->read();
        foreach ($this->parameterRegistry->managedNames() as $parameterName) {
            if (!array_key_exists($parameterName, $cachedConfig)) {
                continue;
            }

            $cachedValue = $cachedConfig[$parameterName];
            if (!\is_string($cachedValue)) {
                throw new ConfigurationException('A managed dynamic secret cache value must be text.');
            }

            if ($cachedValue !== '' && $cachedValue !== self::DATABASE_PLACEHOLDER) {
                return true;
            }

            if ($cachedValue === self::DATABASE_PLACEHOLDER
                && !array_key_exists($parameterName, $storedSecrets)
            ) {
                return true;
            }

            if ($cachedValue === '' && array_key_exists($parameterName, $storedSecrets)) {
                return true;
            }
        }

        foreach ($this->parameterRegistry->hydrateOnlyNames() as $parameterName) {
            if (!array_key_exists($parameterName, $cachedConfig)) {
                continue;
            }

            $cachedValue = $cachedConfig[$parameterName];
            if (!\is_string($cachedValue)) {
                throw new ConfigurationException('A hydrate-only dynamic secret cache value must be text.');
            }

            if (($cachedValue === self::DATABASE_PLACEHOLDER)
                !== array_key_exists($parameterName, $storedSecrets)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $cachedConfig
     * @return array<mixed>
     */
    public function hydrate(array $cachedConfig): array
    {
        $storedSecrets = $this->read();
        foreach ([
            ...$this->parameterRegistry->managedNames(),
            ...$this->parameterRegistry->hydrateOnlyNames(),
        ] as $parameterName) {
            if (($cachedConfig[$parameterName] ?? null) !== self::DATABASE_PLACEHOLDER) {
                continue;
            }

            if (!array_key_exists($parameterName, $storedSecrets)) {
                throw new ConfigurationException(
                    \sprintf(
                        'The private dynamic-secret file is missing the value "%s" referenced by the configuration cache.',
                        $parameterName,
                    ),
                );
            }

            $cachedConfig[$parameterName] = $storedSecrets[$parameterName];
        }

        return $cachedConfig;
    }

    public function getOrCreateExtensionPrivate(string $parameterName, int $bytes = 32): string
    {
        $this->assertExtensionPrivate($parameterName);
        if ($bytes < 32 || $bytes > 1024) {
            throw new \InvalidArgumentException('An extension-private secret must contain 32 to 1024 random bytes.');
        }

        return $this->withExclusiveLock(function () use ($parameterName, $bytes): string {
            $secrets = $this->read();
            if (isset($secrets[$parameterName])) {
                return $secrets[$parameterName];
            }

            $secret                  = sodium_bin2base64(random_bytes($bytes), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $secrets[$parameterName] = $secret;
            $this->write($secrets);

            return $secret;
        });
    }

    public function getExtensionPrivate(string $parameterName): ?string
    {
        $this->assertExtensionPrivate($parameterName);

        return $this->read()[$parameterName] ?? null;
    }

    public function replaceExtensionPrivate(string $parameterName, string $secret): void
    {
        $this->assertExtensionPrivate($parameterName);
        if ($secret === '') {
            throw new \InvalidArgumentException('An extension-private secret cannot be empty.');
        }

        $this->withExclusiveLock(function () use ($parameterName, $secret): void {
            $secrets                 = $this->read();
            $secrets[$parameterName] = $secret;
            $this->write($secrets);
        });
    }

    private function assertExtensionPrivate(string $parameterName): void
    {
        if (!$this->parameterRegistry->isRegisteredExtensionPrivate($parameterName)) {
            throw new \InvalidArgumentException('The extension-private secret is not registered in this process.');
        }
    }

    /** @return array<string, string> */
    private function read(): array
    {
        if (!file_exists($this->filename)) {
            return [];
        }

        if (is_link($this->filename) || !is_file($this->filename)) {
            throw new ConfigurationException('The private dynamic-secret path is not a regular file.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($this->filename);
            if ($permissions === false) {
                throw new ConfigurationException('Unable to inspect the private dynamic-secret permissions.');
            }

            if (($permissions & 0777) !== 0600 && !chmod($this->filename, 0600)) {
                throw new ConfigurationException('Unable to secure the private dynamic-secret file.');
            }
        }

        $data = s2_call_without_warnings(
            fn(): mixed => (static fn(string $filename): mixed => include $filename)($this->filename),
        );
        if (!\is_array($data)) {
            throw new ConfigurationException('The private dynamic-secret file must return an array.');
        }

        $secrets = [];
        foreach ($data as $parameterName => $value) {
            if (!\is_string($parameterName)
                || !$this->parameterRegistry->isAllowedInFile($parameterName)
                || !\is_string($value)
                || $value === ''
            ) {
                throw new ConfigurationException('The private dynamic-secret file contains invalid data.');
            }

            $secrets[$parameterName] = $value;
        }

        ksort($secrets, SORT_STRING);

        return $secrets;
    }

    /**
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    private function withExclusiveLock(\Closure $callback): mixed
    {
        $directory = \dirname($this->filename);
        if (is_link($directory)) {
            throw new ConfigurationException('The private dynamic-secret directory cannot be a symbolic link.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException('Unable to create the private dynamic-secret directory.');
        }

        $lockFilename = $this->filename . '.lock';
        if (is_link($lockFilename)) {
            throw new ConfigurationException('The private dynamic-secret lock cannot be a symbolic link.');
        }

        $lock = s2_call_without_warnings(static fn() => fopen($lockFilename, 'c+b'));
        if ($lock === false) {
            throw new ConfigurationException('Unable to open the private dynamic-secret lock.');
        }

        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($lockFilename, 0600)) {
                throw new ConfigurationException('Unable to secure the private dynamic-secret lock.');
            }

            if (!flock($lock, LOCK_EX)) {
                throw new ConfigurationException('Unable to lock the private dynamic-secret file.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, string> $secrets */
    private function write(array $secrets): void
    {
        ksort($secrets, SORT_STRING);
        $directory = \dirname($this->filename);
        if (is_link($directory)) {
            throw new ConfigurationException('The private dynamic-secret directory cannot be a symbolic link.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException('Unable to create the private dynamic-secret directory.');
        }

        if (is_link($this->filename)) {
            throw new ConfigurationException('The private dynamic-secret file cannot be a symbolic link.');
        }

        $content = "<?php\n\n// This file is automatically generated by Register. Do not edit.\n\nreturn "
            . var_export($secrets, true) . ";\n";
        $temporaryFile = $directory . '/.' . basename($this->filename) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = s2_call_without_warnings(static fn() => fopen($temporaryFile, 'xb'));
        if ($handle === false) {
            throw new ConfigurationException('Unable to create a private dynamic-secret work file.');
        }

        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($temporaryFile, 0600)) {
                throw new ConfigurationException('Unable to secure the private dynamic-secret work file.');
            }

            $remaining = $content;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new ConfigurationException('Unable to write the private dynamic-secret file.');
                }

                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new ConfigurationException('Unable to flush the private dynamic-secret file.');
            }

            if (\function_exists('fsync') && !fsync($handle)) {
                throw new ConfigurationException('Unable to synchronize the private dynamic-secret file.');
            }
        } catch (\Throwable $throwable) {
            fclose($handle);
            unlink($temporaryFile);
            throw $throwable;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        if (!rename($temporaryFile, $this->filename)) {
            unlink($temporaryFile);
            throw new ConfigurationException('Unable to publish the private dynamic-secret file.');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($this->filename, 0600)) {
            throw new ConfigurationException('Unable to secure the private dynamic-secret file.');
        }

        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($this->filename, true);
        }
    }
}
