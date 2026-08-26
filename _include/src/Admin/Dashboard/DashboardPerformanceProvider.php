<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Core\Monitoring\RequestPerformanceInspector;

final readonly class DashboardPerformanceProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer            $templateRenderer,
        private RequestPerformanceInspector $performanceInspector,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render('_admin/templates/dashboard/performance-item.php.inc', [
            'performanceWindows' => $this->performanceInspector->inspectRecentAndDaily(),
        ]);
    }
}
