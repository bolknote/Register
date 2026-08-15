<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup\Admin;

use Psr\Log\LoggerInterface;
use Register\Backup\BackupFile;
use Register\Backup\BackupManager;
use S2\AdminYard\Translator;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\Audit\SecurityAuditLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final readonly class BackupAdminController
{
    public function __construct(
        private BackupManager     $backupManager,
        private BackupToken       $backupToken,
        private PermissionChecker $permissionChecker,
        private AuthManager       $authManager,
        private Translator        $translator,
        private LoggerInterface   $logger,
        private SecurityAuditLogger $securityAuditLogger,
    ) {
    }

    public function create(Request $request): Response
    {
        $denial = $this->authorizeSensitiveOperation($request, 'create');
        if ($denial instanceof Response) {
            return $denial;
        }

        try {
            $response = $this->downloadResponse($this->backupManager->createNow());
            $this->audit('create', SecurityAuditLogger::OUTCOME_SUCCESS);

            return $response;
        } catch (\Throwable $throwable) {
            $this->audit('create', SecurityAuditLogger::OUTCOME_FAILURE);
            $this->logger->error('Manual Register backup failed.', ['exception' => $throwable]);

            return new Response($this->translator->trans('Backup failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function downloadLatest(Request $request): Response
    {
        $denial = $this->authorizeSensitiveOperation($request, 'download');
        if ($denial instanceof Response) {
            return $denial;
        }

        $backup = $this->backupManager->latest();
        if (!$backup instanceof BackupFile) {
            $this->audit('download', SecurityAuditLogger::OUTCOME_FAILURE);

            return new Response($this->translator->trans('No backups yet'), Response::HTTP_NOT_FOUND);
        }

        $response = $this->downloadResponse($backup);
        $this->audit('download', SecurityAuditLogger::OUTCOME_SUCCESS);

        return $response;
    }

    private function authorizeSensitiveOperation(Request $request, string $action): ?Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            $this->audit($action, SecurityAuditLogger::OUTCOME_DENIED);

            return new Response(
                $this->translator->trans('Only POST requests are allowed.'),
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => Request::METHOD_POST],
            );
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            $this->audit($action, SecurityAuditLogger::OUTCOME_DENIED);

            return new Response($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->backupToken->matches($request->request->getString('csrf_token'))) {
            $this->audit($action, SecurityAuditLogger::OUTCOME_DENIED);

            return new Response($this->translator->trans('Invalid backup token'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->authManager->verifyCurrentPassword($request, $request->request->getString('password'))) {
            $this->audit($action, SecurityAuditLogger::OUTCOME_DENIED);

            return new Response($this->translator->trans('Invalid current password'), Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function downloadResponse(BackupFile $backup): BinaryFileResponse
    {
        $response = new BinaryFileResponse($backup->path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->name);
        $response->setPrivate();

        return $response;
    }

    private function audit(string $action, string $outcome): void
    {
        $this->securityAuditLogger->backupOperation(
            $this->permissionChecker->getUserId(),
            $action,
            'manual',
            $outcome,
        );
    }
}
