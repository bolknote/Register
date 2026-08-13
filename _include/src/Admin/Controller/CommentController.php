<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Controller;

use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Controller\EntityController;
use S2\AdminYard\Controller\InvalidRequestException;
use S2\AdminYard\Database\DatabaseHelper;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Database\SafeDataProviderException;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Transformer\ViewTransformer;
use S2\AdminYard\Translator;
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CommentController extends EntityController
{
    public function __construct(
        EntityConfig            $entityConfig,
        EventDispatcher         $eventDispatcher,
        PdoDataProvider         $dataProvider,
        ViewTransformer         $viewTransformer,
        Translator              $translator,
        TemplateRenderer        $templateRenderer,
        FormFactory             $formFactory,
        SettingStorageInterface $settingStorage,
        private readonly SpamFeedbackService $spamFeedbackService,
        private readonly string              $antispamTargetType = 'article',
        private readonly string              $commentTable = 'art_comments',
        private readonly ?\Closure           $commentNotifier = null,
    ) {
        parent::__construct(
            $entityConfig,
            $eventDispatcher,
            $dataProvider,
            $viewTransformer,
            $translator,
            $templateRenderer,
            $formFactory,
            $settingStorage,
        );
    }

    public function hamAction(Request $request): Response
    {
        return $this->feedbackAction($request, 'ham');
    }

    public function spamAction(Request $request): Response
    {
        return $this->feedbackAction($request, 'spam');
    }

    public function rejectAction(Request $request): Response
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            throw new InvalidRequestException('Reject action must be called via POST request.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $primaryKey = $this->getEntityPrimaryKeyFromRequest($request);
        $csrfToken  = $request->request->get('csrf_token');

        $field = $this->entityConfig->findFieldByName('shown');
        if (!$field instanceof \S2\AdminYard\Config\FieldConfig) {
            throw new \LogicException('Field "shown" is not defined.');
        }

        if (!$field->inlineEdit) {
            return new JsonResponse(['errors' => [
                sprintf($this->translator->trans('Action "%s" is not allowed for entity "%s".'), 'reject', $this->entityConfig->getName())
            ]], Response::HTTP_FORBIDDEN);
        }

        // Borrow CSRF token from delete action
        if ($this->getDeleteCsrfToken($primaryKey->toArray()) !== $csrfToken) {
            return new JsonResponse(['errors' => [
                $this->translator->trans('Unable to confirm security token. A likely cause for this is that some time passed between when you first entered the page and when you submitted the form. If that is the case and you would like to continue, submit the form again.')
            ]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->dataProvider->updateEntity(
                $this->entityConfig->getTableName(),
                [
                    'sent' => FieldConfig::DATA_TYPE_BOOL,
                    ... $this->entityConfig->getFieldDataTypes('patch', includePrimaryKey: true)
                ],
                DatabaseHelper::getReadAndWriteAccessControlConditions($this->entityConfig),
                $primaryKey,
                ['sent' => true],
            );
        } catch (SafeDataProviderException $e) {
            $statusCode = $e->getCode();
            return new JsonResponse(['errors' => [$this->translator->trans($e->getMessage())]], $statusCode > 0 ? $statusCode : Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable) {
            return new JsonResponse(['errors' => ['Unable to update entity']], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['success' => true]);
    }

    private function feedbackAction(Request $request, string $label): Response
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            throw new InvalidRequestException('Spam feedback actions must be called via POST request.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $primaryKey = $this->getEntityPrimaryKeyFromRequest($request);
        $field      = $this->entityConfig->findFieldByName('shown');
        if (!$field instanceof FieldConfig) {
            throw new \LogicException('Field "shown" is not defined.');
        }

        if (!$field->inlineEdit) {
            return new JsonResponse(['errors' => [
                sprintf($this->translator->trans('Action "%s" is not allowed for entity "%s".'), $label, $this->entityConfig->getName())
            ]], Response::HTTP_FORBIDDEN);
        }

        if ($this->getDeleteCsrfToken($primaryKey->toArray()) !== $request->request->get('csrf_token')) {
            return new JsonResponse(['errors' => [
                $this->translator->trans('Unable to confirm security token. A likely cause for this is that some time passed between when you first entered the page and when you submitted the form. If that is the case and you would like to continue, submit the form again.')
            ]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = $label === 'ham'
                ? $this->spamFeedbackService->markHam(
                    $primaryKey->getIntId(),
                    $this->antispamTargetType,
                    $this->commentTable,
                    $this->commentNotifier,
                )
                : $this->spamFeedbackService->markSpam(
                    $primaryKey->getIntId(),
                    $this->antispamTargetType,
                    $this->commentTable,
                );
        } catch (\Throwable $throwable) {
            $this->logger?->error('Unable to store spam feedback.', ['exception' => $throwable]);

            return new JsonResponse(['errors' => ['Unable to store spam feedback']], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!$updated) {
            return new JsonResponse(['errors' => ['Comment not found']], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true, 'label' => $label]);
    }
}
