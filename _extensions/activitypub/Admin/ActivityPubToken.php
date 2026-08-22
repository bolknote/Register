<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;

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
