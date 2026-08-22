<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\TemplateRenderer;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Model\PermissionChecker;

readonly class DashboardConfigExtender implements AdminConfigExtenderInterface
{
    /**
     * @param array<mixed> $dashboardStatProviders
     * @param array<mixed> $dashboardBlockProviders
     * @param array<mixed> $systemStatusProviders
     */
    public function __construct(
        private array             $dashboardStatProviders,
        private array             $dashboardBlockProviders,
        private array             $systemStatusProviders,
        private PermissionChecker $permissionChecker,
        private TemplateRenderer  $templateRenderer,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
            return;
        }

        $adminConfig
            ->setServicePage('Dashboard', fn(): string => $this->templateRenderer->render(
                '_admin/templates/dashboard/dashboard.php.inc',
                [
                    'dashboardStatProviders' => $this->dashboardStatProviders,
                ]
            ), 30, 'Overview')
            ->setServicePage('Statistics', fn(): string => $this->templateRenderer->render(
                '_admin/templates/dashboard/statistics.php.inc',
                [
                    'dashboardBlockProviders' => $this->dashboardBlockProviders,
                ]
            ), 31, 'Analytics')
            ->setServicePage('SystemStatus', fn(): string => $this->templateRenderer->render(
                '_admin/templates/dashboard/system-status.php.inc',
                [
                    'systemStatusProviders' => $this->systemStatusProviders,
                ]
            ), 32, 'System status')
        ;
    }
}
