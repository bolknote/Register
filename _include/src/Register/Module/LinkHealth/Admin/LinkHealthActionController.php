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
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Security\Http\AdminMutationGuard;
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
        $result    = match ($operation) {
            'recheck' => $this->recheck($target),
            'ignore'  => $this->ignore($target),
            'unignore' => $this->unignore($target),
            'repair'  => $this->repair($target),
            default   => null,
        };
        if ($result === null) {
            return $this->error('Unknown link action.', Response::HTTP_BAD_REQUEST);
        }

        [$message, $healthStatus] = $result;

        return new JsonResponse([
            'success'            => true,
            'message'            => $message,
            'health_status'      => $healthStatus->value,
            'health_status_label' => $this->translator->trans('Link status ' . $healthStatus->value),
        ]);
    }

    /** @return array{string, LinkHealthStatus} */
    private function recheck(LinkTargetState $target): array
    {
        $this->queuePublisher->publish(
            LinkQueue::targetJobId($target->id),
            LinkQueue::CHECK_CODE,
            LinkQueue::checkPayload($target->id, true),
        );

        return [$this->translator->trans('Link recheck queued'), $target->healthStatus];
    }

    /** @return array{string, LinkHealthStatus} */
    private function ignore(LinkTargetState $target): array
    {
        $this->adminRepository->ignore($target->id);

        return [$this->translator->trans('Link ignored'), LinkHealthStatus::IGNORED];
    }

    /** @return array{string, LinkHealthStatus} */
    private function unignore(LinkTargetState $target): array
    {
        $this->adminRepository->unignore($target->id, time());
        $this->queuePublisher->publish(
            LinkQueue::targetJobId($target->id),
            LinkQueue::CHECK_CODE,
            LinkQueue::checkPayload($target->id, true),
        );

        return [$this->translator->trans('Link restored to checks'), LinkHealthStatus::UNKNOWN];
    }

    /** @return null|array{string, LinkHealthStatus} */
    private function repair(LinkTargetState $target): ?array
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

        return [$this->translator->trans('Link repair queued'), $target->healthStatus];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
