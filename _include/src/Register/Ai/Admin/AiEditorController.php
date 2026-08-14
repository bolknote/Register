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
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminConfigProvider;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AiEditorController
{
    private const int MAX_TEXT_LENGTH = 60000;

    public function __construct(
        private AiClient                $aiClient,
        private AiSettings              $settings,
        private AdminConfigProvider     $adminConfigProvider,
        private SettingStorageInterface $settingStorage,
        private Translator              $translator,
    ) {
    }

    public function generate(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$permissionChecker->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
            return $this->error($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->csrfTokenMatches($request)) {
            return $this->error($this->translator->trans('Invalid AI security token'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->settings->isConfigured()) {
            return $this->error($this->translator->trans('AI is not configured'), Response::HTTP_CONFLICT);
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

    private function csrfTokenMatches(Request $request): bool
    {
        $entityName = $request->request->getString('entity_name');
        if (!\in_array($entityName, ['Article', 'BlogPost'], true)) {
            return false;
        }

        $entityConfig = $this->adminConfigProvider->getAdminConfig()->findEntityByName($entityName);
        if (!$entityConfig instanceof \S2\AdminYard\Config\EntityConfig) {
            return false;
        }

        $contentId = $request->request->getInt('content_id');
        $formAction = $contentId > 0 ? FieldConfig::ACTION_EDIT : FieldConfig::ACTION_NEW;
        $primaryKey = $contentId > 0 ? ['id' => $contentId] : [];
        $expected = (new FormParams(
            $entityName,
            $entityConfig->getFields($formAction),
            $this->settingStorage,
            $formAction,
            $primaryKey,
        ))->getCsrfToken();
        $candidate = $request->request->getString('__csrf_token');

        return $candidate !== '' && hash_equals($expected, $candidate);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
