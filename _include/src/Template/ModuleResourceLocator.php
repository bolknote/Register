<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Template;

use S2\Cms\Framework\ModuleInterface;

/** Resolves private view and page-template resources owned by a module. */
final class ModuleResourceLocator
{
    public static function views(string $rootDir, string $resourceOwner): string
    {
        return self::directory($rootDir, $resourceOwner, 'views');
    }

    public static function templates(string $rootDir, string $resourceOwner): string
    {
        return self::directory($rootDir, $resourceOwner, 'templates');
    }

    private static function directory(string $rootDir, string $resourceOwner, string $resourceType): string
    {
        if (is_a($resourceOwner, ModuleInterface::class, true)) {
            $moduleFile = (new \ReflectionClass($resourceOwner))->getFileName();
            if ($moduleFile === false) {
                throw new \RuntimeException(\sprintf('Unable to locate module "%s" resources.', $resourceOwner));
            }

            return \dirname($moduleFile) . '/resources/' . $resourceType . '/';
        }

        if (preg_match('/^[0-9a-z_]+$/', $resourceOwner) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid optional module identifier "%s".', $resourceOwner));
        }

        return $rootDir . '_extensions/' . $resourceOwner . '/' . $resourceType . '/';
    }
}
