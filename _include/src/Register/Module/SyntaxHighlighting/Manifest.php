<?php
/**
 * Syntax highlighting
 *
 * Highlights code blocks locally with a lazy Highlight.js build.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\SyntaxHighlighting;

use Register\Module\BaseModuleManifestInterface;

final class Manifest implements BaseModuleManifestInterface
{
    #[\Override]
    public function getTitle(): string
    {
        return 'Code highlighting';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Evgeny Stepanischev and Highlight.js contributors';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Highlights common programming languages locally and loads the highlighter only on pages that use code blocks.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0dev';
    }
}
