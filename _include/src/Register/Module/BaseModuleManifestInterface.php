<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module;

/** Describes a mandatory module that forms the Register product. */
interface BaseModuleManifestInterface
{
    public function getTitle(): string;

    public function getAuthor(): string;

    public function getDescription(): string;

    public function getVersion(): string;
}
