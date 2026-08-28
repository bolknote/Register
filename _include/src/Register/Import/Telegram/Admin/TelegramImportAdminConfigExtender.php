<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\AdminYard\Config\AdminConfig;
use Register\Admin\AdminConfigExtenderInterface;
use Register\Core\Model\PermissionChecker;

final readonly class TelegramImportAdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker       $permissionChecker,
        private TelegramImportAdminPage $adminPage,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        if ($this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)
            && $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)
        ) {
            $adminConfig->setServicePage(
                'TelegramImport',
                $this->adminPage->render(...),
                57,
                $this->adminPage->title(),
            );
        }
    }
}
