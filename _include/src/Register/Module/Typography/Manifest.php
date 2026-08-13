<?php
/**
 * Russian typography
 *
 * Converts '""' quotation marks to '«»' and '„“' and puts non-breaking space
 * characters according to Russian typography conventions.
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Module\Typography;

use Register\Module\BaseModuleManifestInterface;

final class Manifest implements BaseModuleManifestInterface
{
    #[\Override]
    public function getTitle(): string
    {
        return 'Russian typography';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Converts \'""\' quotation marks to \'«»\' and \'„“\' and puts non-breaking space characters according to Russian typography conventions.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '2.0dev';
    }
}
