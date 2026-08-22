<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

interface TranslationProviderInterface
{
    /**
     * @return array<mixed>
     */
    public function getTranslations(string $language, string $locale): array;
}
