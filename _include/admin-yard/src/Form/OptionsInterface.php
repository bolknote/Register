<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license http://opensource.org/licenses/MIT MIT
 * @package AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard\Form;

interface OptionsInterface
{
    /**
     * @param array<string, string> $options
     */
    public function setOptions(array $options): void;
}
