<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Profiler;

use Psr\Log\LoggerInterface;
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\UrlBuilder;
use Register\Core\Monitoring\QueryProfilerLog;
use Register\Core\Monitoring\QueryProfilerState;
use Register\Core\Monitoring\RequestQueryProfiler;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class QueryProfilerController
{
    public function __construct(
        private QueryProfilerState   $state,
        private QueryProfilerLog     $log,
        private RequestQueryProfiler $requestProfiler,
        private QueryProfilerToken   $token,
        private PermissionChecker    $permissionChecker,
        private AdminMutationGuard   $mutationGuard,
        private UrlBuilder           $urlBuilder,
        private Translator           $translator,
        private LoggerInterface      $logger,
    ) {
    }

    public function mutate(Request $request): Response
    {
        // Never include the control request itself in a profile session.
        $this->requestProfiler->suppress();

        if (!$this->mutationGuard->isPost($request)) {
            return new Response(
                $this->translator->trans('Only POST requests are allowed.'),
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => Request::METHOD_POST],
            );
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            return new Response($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken($request, $this->token->value())) {
            return new Response($this->translator->trans('Invalid profiler token'), Response::HTTP_FORBIDDEN);
        }

        try {
            match ($request->request->getString('command')) {
                'start_300' => $this->start(300),
                'start_900' => $this->start(900),
                'stop'      => $this->state->stop(),
                'clear'     => $this->log->clear(),
                default     => throw new \InvalidArgumentException('Unknown query profiler command.'),
            };
        } catch (\InvalidArgumentException) {
            return new Response($this->translator->trans('Invalid profiler command'), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $throwable) {
            $this->logger->error('Unable to update the query profiler.', [
                'exception' => $throwable,
                'user_id'   => $this->permissionChecker->getUserId(),
            ]);
            return new Response($this->translator->trans('Query profiler update failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new RedirectResponse(
            $this->urlBuilder->rawLink('/_admin/index.php', ['entity=SystemStatus']) . '#query-profiler',
            Response::HTTP_SEE_OTHER,
        );
    }

    private function start(int $durationSeconds): void
    {
        $this->log->clear();
        $this->state->start($durationSeconds);
    }
}
