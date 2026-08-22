<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Module\LinkHealth\LinkHealthStatus;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Config\BoolProxy;
use Register\Core\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class LinkHealthAdminPage
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private LinkHealthAdminRepository $repository,
        private LinkHealthToken           $token,
        private TemplateRenderer          $templateRenderer,
        private Translator                $translator,
        private RequestStack              $requestStack,
        private BoolProxy                 $autoRepair,
        private PermissionChecker         $permissionChecker,
        private string                    $basePath,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('Link health');
    }

    public function render(): string
    {
        $request   = $this->requestStack->getCurrentRequest();
        $statusRaw = $request instanceof Request ? $request->query->getString('status') : '';
        $status    = $statusRaw === '' ? null : LinkHealthStatus::tryFrom($statusRaw);
        $page      = max(1, $request instanceof Request ? $request->query->getInt('page', 1) : 1);
        $count     = $this->repository->targetCount($status);
        $pageCount = max(1, (int)ceil($count / self::PAGE_SIZE));
        $page      = min($page, $pageCount);

        return $this->templateRenderer->render(__DIR__ . '/../resources/views/admin.php.inc', [
            'summary'     => $this->repository->summary(),
            'targets'     => $this->repository->targets($status, $page, self::PAGE_SIZE),
            'status'      => $status,
            'page'        => $page,
            'pageCount'   => $pageCount,
            'csrfToken'   => $this->token->value(),
            'autoRepair'  => $this->autoRepair->get(),
            'canManage'   => $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE),
            'basePath'    => $this->basePath,
        ]);
    }
}
