<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics\Admin;

use Register\Module\Analytics\AnalyticsReportRepository;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;
use Register\AdminYard\TemplateRenderer;
use Register\Admin\Dashboard\DashboardBlockProviderInterface;

final readonly class DashboardAnalyticsProvider implements DashboardBlockProviderInterface
{
    public function __construct(
        private TemplateRenderer          $templateRenderer,
        private VisitorIdentityRepository $visitorIdentityRepository,
        private AnalyticsReportRepository $reportRepository,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render(
            \dirname(__DIR__) . '/resources/views/dashboard.php.inc',
            [
                'uniqueVisitorsTotal' => $this->visitorIdentityRepository->totalVisitors(),
                'todaySummary'        => $this->reportRepository->summary(date('Y-m-d')),
            ],
        );
    }
}
