<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Extensions;

/** Read-only catalog needed by extension discovery and lifecycle guards. */
interface BaseModuleCatalogInterface
{
    /** @return list<string> */
    public function ids(): array;

    public function contains(string $id): bool;

    /** @return class-string<BaseModuleManifestInterface> */
    public function manifestClass(string $id): string;
}
