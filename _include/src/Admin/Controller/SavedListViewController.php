<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Controller;

use S2\AdminYard\Translator;
use S2\Cms\AdminYard\SavedListViewManager;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SavedListViewController
{
    public function __construct(
        private SavedListViewManager $manager,
        private Translator           $translator,
        private AdminMutationGuard   $mutationGuard,
    ) {
    }

    public function save(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        $response = $this->validateRequest($permissionChecker, $request);
        if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
            return $response;
        }

        $entityName = $request->request->getString('entity');
        $stateJson  = $request->request->getString('state');
        try {
            $state = json_decode($stateJson, true, 32, JSON_THROW_ON_ERROR);
            if (!\is_array($state)) {
                throw new \InvalidArgumentException('Invalid saved view state.');
            }

            $views = $this->manager->save(
                $entityName,
                $request->request->getString('name'),
                $state,
            );
        } catch (\JsonException|\InvalidArgumentException|\LengthException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans($exception->getMessage()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true, 'views' => $views]);
    }

    public function delete(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        $response = $this->validateRequest($permissionChecker, $request);
        if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
            return $response;
        }

        try {
            $views = $this->manager->delete(
                $request->request->getString('entity'),
                $request->request->getString('view_id'),
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans($exception->getMessage()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true, 'views' => $views]);
    }

    private function validateRequest(PermissionChecker $permissionChecker, Request $request): ?JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('Only POST requests are allowed.'),
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('No permission'),
            ], Response::HTTP_FORBIDDEN);
        }

        $entityName = $request->request->getString('entity');
        try {
            $validToken = $this->mutationGuard->hasValidCsrfToken(
                $request,
                $this->manager->csrfToken($entityName),
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans($exception->getMessage()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$validToken) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('Unable to confirm security token.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
