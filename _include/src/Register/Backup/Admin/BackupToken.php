<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup\Admin;

use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;

final readonly class BackupToken
{
    public function __construct(private SettingStorageInterface $settingStorage)
    {
    }

    public function value(): string
    {
        return (new FormParams(
            'RegisterBackup',
            [],
            $this->settingStorage,
            FieldConfig::ACTION_EDIT,
        ))->getCsrfToken();
    }

    public function matches(string $candidate): bool
    {
        return $candidate !== '' && hash_equals($this->value(), $candidate);
    }
}
