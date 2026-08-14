<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

final class BackupDirectoryResolver
{
    public static function resolve(string $rootDir, ?string $configuredDirectory): string
    {
        $rootDir = rtrim($rootDir, '/\\');
        if ($rootDir === '') {
            throw new \InvalidArgumentException('The Register root directory cannot be empty.');
        }

        $configuredDirectory = $configuredDirectory === null ? '' : trim($configuredDirectory);
        if ($configuredDirectory === '') {
            $installationId = substr(hash('sha256', self::normalizePath($rootDir)), 0, 12);

            return \dirname($rootDir) . '/register-backups-' . $installationId;
        }

        if (self::isAbsolute($configuredDirectory)) {
            return rtrim($configuredDirectory, '/\\');
        }

        return $rootDir . '/' . trim($configuredDirectory, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private static function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        return str_replace('\\', '/', $realPath === false ? $path : $realPath);
    }
}
