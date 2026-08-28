<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin;

readonly class TranslationProvider implements TranslationProviderInterface
{
    public function __construct(private string $rootDir)
    {
    }

    /**
     * @return array<mixed>
     */
    #[\Override]
    public function getTranslations(string $language, string $locale): array
    {
        $adminTranslations = require $this->rootDir . '_admin/lang/' . $locale . '/admin.php';
        $adminYardTranslations = require $this->rootDir . '_include/admin-yard/translations/' . $locale . '.php';

        return array_merge($adminTranslations, $adminYardTranslations);
    }
}
