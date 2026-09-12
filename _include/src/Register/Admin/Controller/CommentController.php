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
use Register\AdminYard\Form\Form;
use Register\AdminYard\Event\BeforeRenderEvent;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Core\Comment\CommentHtml;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CommentController extends EntityController
{
    public const array QUEUES = ['pending', 'published', 'hidden', 'spam', 'all'];

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

    #[\Override]
    public function listAction(Request $request): string|Response
    {
        $eventName = 'adminyard.Comment.' . EntityConfig::EVENT_BEFORE_LIST_RENDER;
        $listener = function (BeforeRenderEvent $event): void {
            if ($event->data === null) {
                return;
            }

            $event->data['commentQueue'] = $event->data['filterData']['queue'] ?? 'pending';
            $event->data['commentQueueCounts'] = [];
            $filters = $this->entityConfig->getFilters();
            $queueFilter = $filters['queue'];
            $contextConditions = [];
            foreach ($event->data['filterData'] as $name => $value) {
                if ($name !== 'queue' && isset($filters[$name])) {
                    $contextConditions[] = $filters[$name]->getCondition($value);
                }
            }

            foreach (self::QUEUES as $queue) {
                $event->data['commentQueueCounts'][$queue] = $this->getEntityCount([...$contextConditions, $queueFilter->getCondition($queue)]);
            }
        };
        $this->eventDispatcher->addListener($eventName, $listener);
        try {
            return parent::listAction($request);
        } finally {
            $this->eventDispatcher->removeListener($eventName, $listener);
        }
    }

    #[\Override]
    protected function getListFilterForm(Request $request): Form
    {
        if (!$request->query->has('queue')) {
            // Links from a material or an antispam decision must show that context,
            // including comments which have already been handled.
            $queue = $request->query->has('content_id') || $request->query->has('comment_id')
                || $request->query->has('status') || $request->query->has('published')
                ? 'all'
                : 'pending';
            $request->query->set('queue', $queue);
        }

        if (!\in_array($request->query->getString('queue'), self::QUEUES, true)) {
            $request->query->set('queue', 'pending');
        }

        if (!$request->query->has('apply_filter')) {
            $request->query->set('apply_filter', '0');
        }

        return parent::getListFilterForm($request);
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    #[\Override]
    protected function renderCellsForNormalizedRow(Request $request, array $row, string $actionForFieldRestriction): array
    {
        $result = parent::renderCellsForNormalizedRow($request, $row, $actionForFieldRestriction);
        $result['csrf_token'] = $this->getDeleteCsrfToken($result['primary_key']);
        $label = (string)($row['virtual_spam_label'] ?? '');
        $state = match (true) {
            $label === 'spam' => 'spam',
            (bool)$row['column_shown'] => 'published',
            (bool)$row['column_sent'] => 'hidden',
            default => 'pending',
        };
        $parentText = CommentHtml::plainText((string)($row['virtual_parent_text'] ?? ''), false);
        $result['comment'] = [
            'id' => (int)$row['column_id'],
            'state' => $state,
            'name' => (string)$row['column_nick'],
            'email' => isset($row['column_email']) ? (string)$row['column_email'] : null,
            'ip' => isset($row['column_ip']) ? (string)$row['column_ip'] : null,
            'body_html' => CommentHtml::render((string)$row['column_text'], $this->translator->trans('wrote')),
            'content_type' => (string)$row['column_content_type'],
            'content_id' => (int)$row['column_content_id'],
            'content_title' => (string)($row['virtual_content_title'] ?? ''),
            'parent_name' => (string)($row['virtual_parent_name'] ?? ''),
            'parent_text' => mb_substr($parentText, 0, 360) . (mb_strlen($parentText) > 360 ? '…' : ''),
            'spam_score' => $row['virtual_spam_score'] ?? null,
        ];

        return $result;
    }

    /**
     * Each queue is an ordinary chronological list with an explicit status filter.
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
        $order = $sortField . ' ' . $sortDirection . ', entity.id';

        return $this->dataProvider->getEntityList(
            $this->entityConfig->getTableName(),
            $this->entityConfig->getFieldDataTypes(FieldConfig::ACTION_LIST, true),
            $labels,
            array_merge(
                DatabaseHelper::getReadAccessControlConditions($this->entityConfig),
                $filterConditions,
            ),
            $order,
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
                ['shown' => false, 'sent' => true],
            );
            $comment = $this->commentRepository->find($primaryKey->getIntId());
            if ($comment !== null) {
                $this->liveUpdateRepository->publishComments($comment->contentId);
            }
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
            $accessible = $this->dataProvider->getEntity(
                $this->entityConfig->getTableName(),
                $this->entityConfig->getFieldDataTypes('patch', includePrimaryKey: true),
                [],
                DatabaseHelper::getReadAndWriteAccessControlConditions($this->entityConfig),
                $primaryKey,
            );
            if ($accessible === null) {
                return new JsonResponse(['errors' => [$this->translator->trans('Comment not found')]], Response::HTTP_NOT_FOUND);
            }

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
