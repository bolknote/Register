<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

interface AssetMergeInterface
{
    /**
     * Add a file to be merged
     */
    public function concat(string $fileName): void;

    /**
     * Get the list of merged files
     * @return string[]
     */
    public function getMergedPaths(): array;
}
