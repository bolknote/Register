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
use S2\Cms\Model\PermissionChecker;
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
        private Translator        $translator,
        private LoggerInterface   $logger,
    ) {
    }

    public function create(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return new Response('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return new Response($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->backupToken->matches($request->request->getString('csrf_token'))) {
            return new Response($this->translator->trans('Invalid backup token'), Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->downloadResponse($this->backupManager->createNow());
        } catch (\Throwable $throwable) {
            $this->logger->error('Manual Register backup failed.', ['exception' => $throwable]);

            return new Response($this->translator->trans('Backup failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function downloadLatest(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return new Response('Only GET requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return new Response($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        $backup = $this->backupManager->latest();
        if (!$backup instanceof BackupFile) {
            return new Response($this->translator->trans('No backups yet'), Response::HTTP_NOT_FOUND);
        }

        return $this->downloadResponse($backup);
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
}
