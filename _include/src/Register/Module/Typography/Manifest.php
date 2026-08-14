<?php
/**
 * Locale-aware typography
 *
 * Applies the conventions defined for the active interface locale without
 * changing content when no locale-specific ruleset exists.
 *
 * @copyright 2010-2024 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
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
        return 'Typography';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak and Evgeny Stepanischev; based in part on Dmitry Smirnov';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Applies locale-specific quotation marks, dashes, and non-breaking spaces to rendered content.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '2.0dev';
    }
}
