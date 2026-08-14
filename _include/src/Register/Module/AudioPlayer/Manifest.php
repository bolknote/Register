<?php
/**
 * Audio player
 *
 * Progressively enhances native HTML audio with a dependency-free player.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\AudioPlayer;

use Register\Module\BaseModuleManifestInterface;

final class Manifest implements BaseModuleManifestInterface
{
    #[\Override]
    public function getTitle(): string
    {
        return 'Audio player';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Evgeny Stepanischev; visual design inspired by Jouele';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Enhances native audio controls with an accessible local player and no JavaScript dependencies.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0dev';
    }
}
