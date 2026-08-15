<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup\Admin;

use Register\Backup\BackupManager;
use Register\Backup\BackupScheduler;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\Dashboard\SystemStatusProviderInterface;
use S2\Cms\Model\PermissionChecker;

final readonly class DashboardBackupProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer  $templateRenderer,
        private BackupManager     $backupManager,
        private BackupScheduler   $backupScheduler,
        private BackupToken       $backupToken,
        private PermissionChecker $permissionChecker,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return '';
        }

        return $this->templateRenderer->render(\dirname(__DIR__) . '/resources/views/dashboard-backup.php.inc', [
            'latestBackup'   => $this->backupManager->latest(),
            'automatic'      => $this->backupScheduler->isEnabled(),
            'retention'      => $this->backupManager->retention(),
            'csrfToken'      => $this->backupToken->value(),
        ]);
    }
}
