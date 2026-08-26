<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Psr\Log\LoggerInterface;
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Import\Telegram\TelegramDiscussionArchive;
use Register\Import\Telegram\TelegramImportService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class TelegramImportAdminController
{
    public function __construct(
        private PermissionChecker     $permissionChecker,
        private TelegramImportToken   $token,
        private TelegramImportService $importService,
        private AdminMutationGuard    $mutationGuard,
        private Translator            $translator,
        private LoggerInterface       $logger,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken($request, $this->token->value())) {
            return $this->error('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('telegram_json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('Telegram JSON upload failed.', Response::HTTP_BAD_REQUEST);
        }

        $size = $file->getSize();
        if (!\is_int($size) || $size <= 0 || $size > TelegramDiscussionArchive::MAX_BYTES) {
            return $this->error('Telegram JSON is too large or empty.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $report = $this->importService->importFile(
                $file->getPathname(),
                $this->permissionChecker->getUserId(),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException|\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST, false);
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram import failed.', [
                'exception' => $throwable,
                'user_id'   => $this->permissionChecker->getUserId(),
            ]);
            return $this->error('Telegram import failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Telegram import completed.', [
            'user_id' => $this->permissionChecker->getUserId(),
            'source'  => $report['source'],
            'changes' => $report['changes'],
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => $this->translator->trans('Telegram import completed.'),
            'report'  => $report,
        ]);
    }

    private function error(string $message, int $status, bool $translate = true): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $translate ? $this->translator->trans($message) : $message,
        ], $status);
    }
}
