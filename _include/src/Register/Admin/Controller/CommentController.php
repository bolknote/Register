<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Controller;

use Register\Comment\CommentRepository;
use Register\Live\LiveUpdateRepository;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Controller\EntityController;
use Register\AdminYard\Controller\InvalidRequestException;
use Register\AdminYard\Database\DatabaseHelper;
use Register\AdminYard\Database\LogicalExpression;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\SafeDataProviderException;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Core\Security\Http\AdminMutationGuard;
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
        private readonly AdminMutationGuard $mutationGuard,
        private readonly CommentRepository $commentRepository,
        private readonly LiveUpdateRepository $liveUpdateRepository,
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

    /**
     * Keep comments awaiting a moderation decision above every ordinary list sort.
     *
     * @param LogicalExpression[] $filterConditions
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    protected function getEntityList(
        array   $filterConditions,
        int     $page,
        ?string $sortField,
        ?string $sortDirection,
    ): array {
        $sortField = $this->entityConfig->modifySortableField($sortField);
        if ($sortField === null) {
            $sortField     = 'time';
            $sortDirection = 'desc';
        } else {
            $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';
        }

        $labels = DatabaseHelper::getSqlExpressionsForAssociations(
            $this->entityConfig,
            FieldConfig::ACTION_LIST,
        );
        $labels['write_access_control'] = $this->entityConfig->getWriteAccessControl()
            ?? LogicalExpression::true();

        $limit = $this->entityConfig->getLimit();
        $offset = $limit === null || $page < 1 ? 0 : ($page - 1) * $limit;
        $pendingFirstOrder = 'CASE WHEN entity.shown = 0 AND entity.sent = 0 THEN 0 ELSE 1 END ASC, '
            . $sortField . ' ' . $sortDirection . ', entity.id';

        return $this->dataProvider->getEntityList(
            $this->entityConfig->getTableName(),
            $this->entityConfig->getFieldDataTypes(FieldConfig::ACTION_LIST, true),
            $labels,
            array_merge(
                DatabaseHelper::getReadAccessControlConditions($this->entityConfig),
                $filterConditions,
            ),
            $pendingFirstOrder,
            'desc',
            $limit,
            $offset,
        );
    }

    #[\Override]
    public function deleteAction(Request $request): Response
    {
        $comment = $this->commentRepository->find(
            $this->getEntityPrimaryKeyFromRequest($request)->getIntId(),
        );
        $response = parent::deleteAction($request);
        if ($response->isSuccessful() && $comment instanceof \Register\Comment\Comment) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
        }

        return $response;
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
        if (!$this->mutationGuard->isPost($request)) {
            throw new InvalidRequestException('Reject action must be called via POST request.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $primaryKey = $this->getEntityPrimaryKeyFromRequest($request);

        $field = $this->entityConfig->findFieldByName('shown');
        if (!$field instanceof \Register\AdminYard\Config\FieldConfig) {
            throw new \LogicException('Field "shown" is not defined.');
        }

        if (!$field->inlineEdit) {
            return new JsonResponse(['errors' => [
                sprintf($this->translator->trans('Action "%s" is not allowed for entity "%s".'), 'reject', $this->entityConfig->getName())
            ]], Response::HTTP_FORBIDDEN);
        }

        // Borrow CSRF token from delete action
        if (!$this->mutationGuard->hasValidCsrfToken(
            $request,
            $this->getDeleteCsrfToken($primaryKey->toArray()),
        )) {
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
        if (!$this->mutationGuard->isPost($request)) {
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

        if (!$this->mutationGuard->hasValidCsrfToken(
            $request,
            $this->getDeleteCsrfToken($primaryKey->toArray()),
        )) {
            return new JsonResponse(['errors' => [
                $this->translator->trans('Unable to confirm security token. A likely cause for this is that some time passed between when you first entered the page and when you submitted the form. If that is the case and you would like to continue, submit the form again.')
            ]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = $label === 'ham'
                ? $this->spamFeedbackService->markHam(
                    $primaryKey->getIntId(),
                )
                : $this->spamFeedbackService->markSpam(
                    $primaryKey->getIntId(),
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
