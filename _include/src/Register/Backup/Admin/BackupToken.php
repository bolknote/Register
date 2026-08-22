<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup\Admin;

use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\Core\Security\Http\AdminMutationGuard;

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
        return AdminMutationGuard::tokensMatch($this->value(), $candidate);
    }
}
