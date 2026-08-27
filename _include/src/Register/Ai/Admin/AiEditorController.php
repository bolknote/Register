<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Ai\Admin;

use Register\Ai\AiClient;
use Register\Ai\AiException;
use Register\Ai\AiSettings;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\Translator;
use Register\Core\Admin\AdminConfigProvider;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AiEditorController
{
    private const int MAX_TEXT_LENGTH = 60000;

    private const array AVAILABILITY_CONFIG_KEYS = [
        AiSettings::PROVIDER_CONFIG_KEY,
        AiSettings::API_KEY_CONFIG_KEY,
        AiSettings::MODEL_CONFIG_KEY,
        AiSettings::FOLDER_ID_CONFIG_KEY,
        AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY,
        AiSettings::GIGACHAT_SCOPE_CONFIG_KEY,
    ];

    public function __construct(
        private AiClient                $aiClient,
        private AiSettings              $settings,
        private AdminConfigProvider     $adminConfigProvider,
        private SettingStorageInterface $settingStorage,
        private Translator              $translator,
        private AdminMutationGuard      $mutationGuard,
        private AiImageLoader           $imageLoader,
    ) {
    }

    public function generate(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        $requestError = $this->requestError($permissionChecker, $request);
        if ($requestError instanceof JsonResponse) {
            return $requestError;
        }

        $action = $request->request->getString('ai_action');
        $title  = trim($request->request->getString('title'));
        $text   = trim($request->request->getString('text'));
        if (!AiClient::supportsAction($action) || $text === '') {
            return $this->error($this->translator->trans('Invalid AI request'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return $this->error($this->translator->trans('AI text is too long'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return new JsonResponse([
                'success' => true,
                'result'  => $this->aiClient->generate($action, mb_substr($title, 0, 500), $text),
                'target'  => $action,
            ]);
        } catch (AiException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_GATEWAY);
        }
    }

    public function generateAlt(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        $requestError = $this->requestError($permissionChecker, $request);
        if ($requestError instanceof JsonResponse) {
            return $requestError;
        }

        if (!$this->settings->autoAltEnabled() || !$this->settings->supportsImageInput()) {
            return $this->error($this->translator->trans('AI image input unavailable'), Response::HTTP_CONFLICT);
        }

        $source = $request->request->getString('image_src');
        $title  = trim($request->request->getString('title'));
        $text   = trim($request->request->getString('text'));
        if ($source === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return $this->error($this->translator->trans('Invalid AI request'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return new JsonResponse([
                'success' => true,
                'result'  => $this->aiClient->generateImageAlt(
                    mb_substr($title, 0, 500),
                    $text,
                    $this->imageLoader->load($source),
                ),
                'target'    => 'image_alt',
                'image_src' => $source,
            ]);
        } catch (AiException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_GATEWAY);
        }
    }

    public function checkAvailability(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return $this->error($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        $configKey = $request->request->getString('config_key');
        if (!\in_array($configKey, self::AVAILABILITY_CONFIG_KEYS, true)
            || !$this->mutationGuard->hasValidCsrfToken(
                $request,
                $this->configCsrfToken($configKey),
                '__csrf_token',
            )
        ) {
            return $this->error($this->translator->trans('Invalid AI security token'), Response::HTTP_FORBIDDEN);
        }

        if ($this->settings->provider() === AiSettings::PROVIDER_DISABLED) {
            return new JsonResponse([
                'success' => true,
                'status'  => 'disabled',
                'message' => $this->translator->trans('AI availability disabled'),
            ]);
        }

        if (!$this->settings->isConfigured()) {
            return new JsonResponse([
                'success' => true,
                'status'  => 'incomplete',
                'message' => $this->translator->trans('AI availability incomplete'),
            ]);
        }

        try {
            $this->aiClient->checkAvailability();
        } catch (AiException $exception) {
            return new JsonResponse([
                'success' => false,
                'status'  => 'unavailable',
                'message' => sprintf(
                    $this->translator->trans('AI availability unavailable'),
                    $exception->getMessage(),
                ),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'success' => true,
            'status'  => 'available',
            'message' => sprintf(
                $this->translator->trans('AI availability available'),
                $this->settings->provider(),
                $this->settings->model(),
            ),
        ]);
    }

    private function requestError(PermissionChecker $permissionChecker, Request $request): ?JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$permissionChecker->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
            return $this->error($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken(
            $request,
            $this->csrfToken($request),
            '__csrf_token',
        )) {
            return $this->error($this->translator->trans('Invalid AI security token'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->settings->isConfigured()) {
            return $this->error($this->translator->trans('AI is not configured'), Response::HTTP_CONFLICT);
        }

        return null;
    }

    private function csrfToken(Request $request): string
    {
        $entityName = $request->request->getString('entity_name');
        if ($entityName !== 'Article') {
            return '';
        }

        $entityConfig = $this->adminConfigProvider->getAdminConfig()->findEntityByName($entityName);
        if (!$entityConfig instanceof \Register\AdminYard\Config\EntityConfig) {
            return '';
        }

        $contentId = $request->request->getInt('content_id');
        $formAction = $contentId > 0 ? FieldConfig::ACTION_EDIT : FieldConfig::ACTION_NEW;
        $primaryKey = $contentId > 0 ? ['id' => $contentId] : [];
        return (new FormParams(
            $entityName,
            $entityConfig->getFields($formAction),
            $this->settingStorage,
            $formAction,
            $primaryKey,
        ))->getCsrfToken();
    }

    private function configCsrfToken(string $configKey): string
    {
        return (new FormParams(
            'Config',
            ['value' => new FieldConfig('value')],
            $this->settingStorage,
            'patch',
            ['name' => $configKey],
        ))->getCsrfToken();
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
