<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\AdminYard\TemplateRenderer;
use Register\Admin\Dashboard\DashboardStatProviderInterface;
use Register\Core\Model\PermissionChecker;

/** Renders actionable editorial lists for the signed-in author. */
final readonly class DashboardContentProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private BlogOverviewRepository $repository,
        private PermissionChecker $permissions,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        $now = time();
        $canWrite = $this->permissions->isGrantedAny(
            PermissionChecker::PERMISSION_CREATE_ARTICLES,
            PermissionChecker::PERMISSION_EDIT_SITE,
        );
        $canModerate = $this->permissions->isGrantedAny(
            PermissionChecker::PERMISSION_HIDE_COMMENTS,
            PermissionChecker::PERMISSION_EDIT_COMMENTS,
        );
        return $this->templateRenderer->render('_admin/templates/dashboard/publication-item.php.inc', [
            'overview' => $this->repository->snapshot(
                $now, $canWrite,
                $this->permissions->isGranted(PermissionChecker::PERMISSION_EDIT_SITE),
                $this->permissions->getUserId(), $canModerate,
            ),
            'now' => $now,
            'canWrite' => $canWrite,
            'canModerate' => $canModerate,
        ]);
    }
}
