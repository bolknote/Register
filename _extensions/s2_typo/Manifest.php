<?php
/**
 * Russian typography
 *
 * Converts '""' quotation marks to '«»' and '„“' and puts non-breaking space
 * characters according to Russian typography conventions.
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license MIT
 * @package s2_typo
 */

declare(strict_types = 1);

namespace s2_extensions\s2_typo;

use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Extensions\ManifestTrait;

class Manifest implements ManifestInterface
{
    use ManifestTrait;

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
