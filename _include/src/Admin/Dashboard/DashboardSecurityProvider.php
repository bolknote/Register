<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Core\Security\Monitoring\SecurityAlertDetector;

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
