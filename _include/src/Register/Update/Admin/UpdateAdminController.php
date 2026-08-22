<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update\Admin;

use Psr\Log\LoggerInterface;
use Register\Update\UpdateManager;
use Register\AdminYard\Translator;
use Register\Core\Model\AuthManager;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateAdminController
{
    public function __construct(
        private UpdateManager       $updateManager,
        private UpdateToken         $updateToken,
        private PermissionChecker   $permissionChecker,
        private AuthManager         $authManager,
        private AdminMutationGuard  $mutationGuard,
        private Translator          $translator,
        private LoggerInterface     $logger,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        return $this->run($request, false, fn(): array => $this->updateManager->start(
            $request->request->getString('filename'),
            $request->request->getInt('size'),
        ));
    }

    public function chunk(Request $request): JsonResponse
    {
        return $this->run($request, false, function () use ($request): array {
            $chunk = $request->files->get('chunk');
            if (!$chunk instanceof UploadedFile || !$chunk->isValid()) {
                throw new \RuntimeException('The release upload chunk is missing or invalid.');
            }

            return $this->updateManager->append(
                $request->request->getString('id'),
                $request->request->getInt('offset'),
                $chunk->getPathname(),
            );
        });
    }

    public function prepare(Request $request): JsonResponse
    {
        return $this->run($request, false, function () use ($request): array {
            $this->extendExecutionTime();

            return $this->updateManager->prepare($request->request->getString('id'));
        });
    }

    public function apply(Request $request): JsonResponse
    {
        return $this->run($request, true, function () use ($request): array {
            $this->extendExecutionTime();

            return $this->updateManager->apply($request->request->getString('id'));
        });
    }

    public function finish(Request $request): JsonResponse
    {
        return $this->run($request, true, function () use ($request): array {
            $this->extendExecutionTime();

            return $this->updateManager->finish($request->request->getString('id'));
        });
    }

    public function status(Request $request): JsonResponse
    {
        return $this->run(
            $request,
            false,
            fn(): array => $this->updateManager->status($request->request->getString('id')),
        );
    }

    /**
     * @param callable():array $operation
     * @phpstan-param callable(): array<string, mixed> $operation
     */
    private function run(Request $request, bool $passwordRequired, callable $operation): JsonResponse
    {
        $denial = $this->authorize($request, $passwordRequired);
        if ($denial instanceof JsonResponse) {
            return $denial;
        }

        try {
            return new JsonResponse(['success' => true, 'state' => $operation()]);
        } catch (\Throwable $throwable) {
            $this->logger->error('Register administration update operation failed.', [
                'exception' => $throwable,
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function authorize(Request $request, bool $passwordRequired): ?JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return $this->error('No permission', Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken($request, $this->updateToken->value())) {
            return $this->error('Invalid update token', Response::HTTP_FORBIDDEN);
        }

        if ($passwordRequired
            && !$this->authManager->verifyCurrentPassword($request, $request->request->getString('password'))
        ) {
            return $this->error('Invalid current password', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }

    private function extendExecutionTime(): void
    {
        if (\function_exists('set_time_limit')) {
            set_time_limit(300);
        }
    }
}
