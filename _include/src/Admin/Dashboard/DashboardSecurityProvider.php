<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Dashboard;

use S2\AdminYard\TemplateRenderer;
use S2\Cms\Security\Monitoring\SecurityAlertDetector;

final readonly class DashboardSecurityProvider implements DashboardStatProviderInterface, SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer      $templateRenderer,
        private SecurityAlertDetector $alertDetector,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render('_admin/templates/dashboard/security-item.php.inc', [
            'securitySummary' => $this->alertDetector->inspect(),
        ]);
    }
}
