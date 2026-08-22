<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;

final readonly class ActivityPubToken
{
    public function __construct(private SettingStorageInterface $settingStorage)
    {
    }

    public function value(): string
    {
        return (new FormParams(
            'ActivityPub',
            [],
            $this->settingStorage,
            FieldConfig::ACTION_EDIT,
        ))->getCsrfToken();
    }
}
