<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module;

use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Framework\ModuleInterface;

/**
 * The product modules that make up every Register installation.
 *
 * Keeping the list here makes the product contract independent from extension database rows while
 * the remaining S2-era code moves into Register namespaces.
 */
final class BaseModuleRegistry
{
    public const string BLOG       = 's2_blog';

    public const string SEARCH     = 's2_search';

    public const string MATH       = 's2_latex';

    public const string ANALYTICS  = 's2_counter';

    public const string TYPOGRAPHY = 's2_typo';

    /**
     * The order preserves the established listener and route registration order.
     *
     * @var array<string, array{
     *     manifest: class-string<ManifestInterface>,
     *     application: class-string<ModuleInterface>,
     *     admin: class-string<ModuleInterface>|null
     * }>
     */
    private const array MODULES = [
        self::BLOG => [
            'manifest'    => \s2_extensions\s2_blog\Manifest::class,
            'application' => \s2_extensions\s2_blog\Extension::class,
            'admin'       => \s2_extensions\s2_blog\AdminExtension::class,
        ],
        self::SEARCH => [
            'manifest'    => Search\Manifest::class,
            'application' => Search\Module::class,
            'admin'       => Search\AdminModule::class,
        ],
        self::MATH => [
            'manifest'    => Math\Manifest::class,
            'application' => Math\Module::class,
            'admin'       => Math\AdminModule::class,
        ],
        self::ANALYTICS => [
            'manifest'    => Analytics\Manifest::class,
            'application' => Analytics\Module::class,
            'admin'       => Analytics\AdminModule::class,
        ],
        self::TYPOGRAPHY => [
            'manifest'    => Typography\Manifest::class,
            'application' => Typography\Module::class,
            'admin'       => null,
        ],
    ];

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys(self::MODULES);
    }

    public function contains(string $id): bool
    {
        return isset(self::MODULES[$id]);
    }

    /** @return class-string<ManifestInterface> */
    public function manifestClass(string $id): string
    {
        return $this->module($id)['manifest'];
    }

    /** @return list<class-string<ModuleInterface>> */
    public function applicationModuleClasses(): array
    {
        return array_column(self::MODULES, 'application');
    }

    /** @return list<class-string<ModuleInterface>> */
    public function adminModuleClasses(): array
    {
        $classes = [];
        foreach (self::MODULES as $module) {
            if ($module['admin'] !== null) {
                $classes[] = $module['admin'];
            }
        }

        return $classes;
    }

    /**
     * @return array{
     *     manifest: class-string<ManifestInterface>,
     *     application: class-string<ModuleInterface>,
     *     admin: class-string<ModuleInterface>|null
     * }
     */
    private function module(string $id): array
    {
        return self::MODULES[$id]
            ?? throw new \InvalidArgumentException(\sprintf('Unknown Register base module "%s".', $id));
    }
}
