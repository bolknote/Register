<?php
/**
 * LaTeX
 *
 * Integrates site with i.upmath.me service
 *
 * @copyright 2011-2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Module\Math;

use Register\Module\BaseModuleManifestInterface;

final class Manifest implements BaseModuleManifestInterface
{
    #[\Override]
    public function getTitle(): string
    {
        return 'LaTeX';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Allows to write LaTeX formulas.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '2.0dev';
    }
}
