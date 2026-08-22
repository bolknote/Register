<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Psr\Log\LoggerInterface;
use Register\Content\ContentType;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\Translator;
use Register\Core\Admin\AdminConfigProvider;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Extension\activitypub\Application\ContentFederationPreview;
use Register\Extension\activitypub\Application\ContentFederationPreviewService;
use Register\Extension\activitypub\Domain\ContentProjectionAction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticated, CSRF-protected and side-effect-free editorial preview endpoint. */
final readonly class ActivityPubContentPreviewController
{
    public function __construct(
        private AdminConfigProvider                  $adminConfigProvider,
        private FormFactory                          $formFactory,
        private SettingStorageInterface              $settingStorage,
        private AdminMutationGuard                   $mutationGuard,
        private ContentFederationSettingsFormParser  $settingsParser,
        private ContentFederationPreviewService      $previewService,
        private Translator                           $translator,
        private LoggerInterface                      $logger,
    ) {
    }

    public function preview(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $entityName = $request->request->getString('entity_name');
        $contentType = match ($entityName) {
            'BlogPost' => ContentType::POST,
            'Article'  => ContentType::PAGE,
            default    => null,
        };
        if (!$contentType instanceof ContentType) {
            return $this->error('The ActivityPub preview content type is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contentId = $request->request->getInt('content_id');
        $formAction = $contentId > 0 ? FieldConfig::ACTION_EDIT : FieldConfig::ACTION_NEW;
        if ($formAction === FieldConfig::ACTION_NEW) {
            if ($contentType !== ContentType::POST
                || !$permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES)
            ) {
                return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
            }
        } elseif (!$permissionChecker->isGrantedAny(
            PermissionChecker::PERMISSION_CREATE_ARTICLES,
            PermissionChecker::PERMISSION_EDIT_SITE,
        )) {
            return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
        }

        $userId = $permissionChecker->getUserId();
        if ($userId === null || $userId < 1) {
            return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
        }

        $entity = $this->adminConfigProvider->getAdminConfig()->findEntityByName($entityName);
        if (!$entity instanceof \Register\AdminYard\Config\EntityConfig) {
            return $this->error('The ActivityPub preview editor is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $form = $this->formFactory->createEntityForm(new FormParams(
            $entityName,
            $entity->getFields($formAction),
            $this->settingStorage,
            $formAction,
            $contentId > 0 ? ['id' => $contentId] : [],
        ));
        $form->submit($request);
        if (!$form->isValid()) {
            return new JsonResponse([
                'success'      => false,
                'message'      => $this->translator->trans('The ActivityPub preview form contains errors.'),
                'errors'       => $form->getGlobalFormErrors(),
                'field_errors' => $form->getFieldErrors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $formData = $form->getData();
            $preview = $this->previewService->preview(
                $contentType,
                $contentId > 0 ? $contentId : null,
                $formData,
                $this->settingsParser->parse($contentType, $formData),
                $userId,
                $permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE),
                time(),
            );

            return new JsonResponse($this->payload($preview));
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            $this->logger->error('ActivityPub content preview failed.', [
                'content_type' => $contentType->value,
                'content_id'   => $contentId > 0 ? $contentId : null,
                'exception'    => $exception,
            ]);

            return $this->error('The ActivityPub preview could not be built.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /** @return array<string, mixed> */
    private function payload(ContentFederationPreview $preview): array
    {
        $prettyJson = '';
        $contentHtml = '';
        if ($preview->document !== null) {
            $normalized = json_decode($preview->canonicalJson, true, 512, JSON_THROW_ON_ERROR);
            $prettyJson = json_encode(
                $normalized,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $content = $preview->document['content'] ?? '';
            $contentHtml = \is_string($content) ? $content : '';
        }

        return [
            'success'             => true,
            'action'              => $preview->action->value,
            'message'             => $this->translator->trans($this->actionMessage($preview->action)),
            'canonical_url'       => $preview->canonicalUrl,
            'owner_handle'        => $preview->ownerHandle,
            'content_published'   => $preview->contentPublished,
            'federation_enabled'  => $preview->federationEnabled,
            'provisional_fields'  => $preview->provisionalFields,
            'provisional_message' => $preview->provisionalFields === []
                ? ''
                : $this->translator->trans('ActivityPub preview provisional fields'),
            'content_html'        => $contentHtml,
            'canonical_json'      => $preview->canonicalJson,
            'pretty_json'         => $prettyJson,
        ];
    }

    private function actionMessage(ContentProjectionAction $action): string
    {
        return match ($action) {
            ContentProjectionAction::SKIPPED    => 'ActivityPub preview action skipped',
            ContentProjectionAction::UNCHANGED  => 'ActivityPub preview action unchanged',
            ContentProjectionAction::CREATED    => 'ActivityPub preview action created',
            ContentProjectionAction::UPDATED    => 'ActivityPub preview action updated',
            ContentProjectionAction::REPLACED   => 'ActivityPub preview action replaced',
            ContentProjectionAction::TOMBSTONED => 'ActivityPub preview action tombstoned',
        };
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
