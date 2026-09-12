<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics\Admin;

use Register\Admin\Dashboard\DashboardStatProviderInterface;
use Register\AdminYard\TemplateRenderer;
use Register\Core\Model\PermissionChecker;
use Register\Module\Analytics\AnalyticsOverviewRepository;

final readonly class OverviewAnalyticsProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer            $templateRenderer,
        private AnalyticsOverviewRepository $overviewRepository,
        private PermissionChecker           $permissionChecker,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render(
            \dirname(__DIR__) . '/resources/views/overview.php.inc',
            [
                'audience'         => $this->overviewRepository->overview(new \DateTimeImmutable('today')),
                'canViewAnalytics' => $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN),
            ],
        );
    }
}
