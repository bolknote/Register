<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update\Admin;

use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;

final readonly class UpdateToken
{
    public function __construct(private SettingStorageInterface $settingStorage)
    {
    }

    public function value(): string
    {
        return (new FormParams(
            'RegisterUpdate',
            [],
            $this->settingStorage,
            FieldConfig::ACTION_EDIT,
        ))->getCsrfToken();
    }
}
