<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Module\LinkHealth\ArchiveStatus;
use Register\Module\LinkHealth\LinkHealthRepository;
use Register\Module\LinkHealth\LinkHealthStatus;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\LinkQueue;
use Register\Module\LinkHealth\LinkTargetState;
use S2\AdminYard\Translator;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LinkHealthActionController
{
    public function __construct(
        private PermissionChecker         $permissionChecker,
        private LinkHealthToken           $token,
        private LinkHealthRepository      $healthRepository,
        private LinkHealthAdminRepository $adminRepository,
        private QueuePublisher            $queuePublisher,
        private Translator                $translator,
        private AdminMutationGuard        $mutationGuard,
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

        $targetId = $request->request->getInt('target_id');
        $target   = $this->healthRepository->findTarget($targetId);
        if (!$target instanceof LinkTargetState) {
            return $this->error('Link target not found.', Response::HTTP_NOT_FOUND);
        }

        if ($target->kind !== LinkKind::EXTERNAL) {
            return $this->error('Link target not found.', Response::HTTP_NOT_FOUND);
        }

        $operation = $request->request->getString('operation');
        $message   = match ($operation) {
            'recheck' => $this->recheck($target),
            'ignore'  => $this->ignore($target),
            'unignore' => $this->unignore($target),
            'repair'  => $this->repair($target),
            default   => null,
        };
        if ($message === null) {
            return $this->error('Unknown link action.', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['success' => true, 'message' => $message]);
    }

    private function recheck(LinkTargetState $target): string
    {
        $this->queuePublisher->publish(
            LinkQueue::targetJobId($target->id),
            LinkQueue::CHECK_CODE,
            LinkQueue::checkPayload($target->id, true),
        );

        return $this->translator->trans('Link recheck queued');
    }

    private function ignore(LinkTargetState $target): string
    {
        $this->adminRepository->ignore($target->id);
        return $this->translator->trans('Link ignored');
    }

    private function unignore(LinkTargetState $target): string
    {
        $this->adminRepository->unignore($target->id, time());
        $this->queuePublisher->publish(
            LinkQueue::targetJobId($target->id),
            LinkQueue::CHECK_CODE,
            LinkQueue::checkPayload($target->id, true),
        );

        return $this->translator->trans('Link restored to checks');
    }

    private function repair(LinkTargetState $target): ?string
    {
        if ($target->healthStatus !== LinkHealthStatus::BROKEN
            || $target->archiveStatus !== ArchiveStatus::AVAILABLE
            || $target->archiveUrl === null
        ) {
            return null;
        }

        $this->queuePublisher->publish(
            LinkQueue::targetJobId($target->id),
            LinkQueue::REPAIR_CODE,
            ['target_id' => $target->id],
        );

        return $this->translator->trans('Link repair queued');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
