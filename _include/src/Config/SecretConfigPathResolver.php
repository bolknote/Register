<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;

final class SecretConfigPathResolver
{
    public const string PRIVATE_FILENAME = 'config.secrets.php';

    public static function resolve(
        string $applicationRoot,
        string $publicRoot,
        ?string $configuredFilename,
    ): string {
        $applicationRoot = self::normalizeRoot($applicationRoot, 'application');
        $publicRoot      = self::normalizeRoot($publicRoot, 'public');
        $configuredFilename = $configuredFilename === null ? '' : trim($configuredFilename);

        if ($configuredFilename !== '') {
            if (self::isAbsolute($configuredFilename)) {
                return $configuredFilename;
            }

            return $applicationRoot . '/' . ltrim($configuredFilename, '/\\');
        }

        if (!self::isWithin($applicationRoot, $publicRoot)) {
            return $applicationRoot . '/' . self::PRIVATE_FILENAME;
        }

        $installationId = substr(hash('sha256', self::canonicalPath($applicationRoot)), 0, 12);

        return \dirname($publicRoot) . '/register-secrets-' . $installationId . '.php';
    }

    public static function fallbackFilename(): string
    {
        return self::PRIVATE_FILENAME;
    }

    private static function normalizeRoot(string $root, string $label): string
    {
        $root = rtrim(trim($root), '/\\');
        if ($root === '') {
            throw new \InvalidArgumentException('The Register ' . $label . ' root directory cannot be empty.');
        }

        return $root;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        $path      = self::canonicalPath($path);
        $directory = self::canonicalPath($directory);

        return $path === $directory || str_starts_with($path . '/', $directory . '/');
    }

    private static function canonicalPath(string $path): string
    {
        $resolved = realpath($path);
        $path     = $resolved === false ? $path : $resolved;

        $path = str_replace('\\', '/', rtrim($path, '/\\'));

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
