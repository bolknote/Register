<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Admin\Profiler\QueryProfilerToken;
use Register\Core\Model\PermissionChecker;
use Register\Core\Monitoring\QueryProfilerInspector;
use Register\Core\Monitoring\QueryProfilerState;

final readonly class DashboardQueryProfilerProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer        $templateRenderer,
        private QueryProfilerState      $state,
        private QueryProfilerInspector  $inspector,
        private QueryProfilerToken      $token,
        private PermissionChecker       $permissionChecker,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            return '';
        }

        return $this->templateRenderer->render('_admin/templates/dashboard/query-profiler-item.php.inc', [
            'profilerState' => $this->state->status(),
            'profile'       => $this->inspector->inspect(),
            'csrfToken'     => $this->token->value(),
        ]);
    }
}
