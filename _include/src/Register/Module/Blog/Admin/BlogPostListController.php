<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Controller\EntityController;
use Register\AdminYard\Database\DatabaseHelper;
use Register\AdminYard\Database\LogicalExpression;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Event\BeforeRenderEvent;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Finds posts in administration and opens the existing public editor. */
final class BlogPostListController extends EntityController
{
    public function __construct(
        EntityConfig $entityConfig,
        EventDispatcher $eventDispatcher,
        PdoDataProvider $dataProvider,
        ViewTransformer $viewTransformer,
        Translator $translator,
        TemplateRenderer $templateRenderer,
        FormFactory $formFactory,
        SettingStorageInterface $settingStorage,
        private readonly ContentUrlGenerator $urls,
        private readonly BlogUrlBuilder $blogUrls,
        private readonly PermissionChecker $permissions,
    ) {
        parent::__construct($entityConfig, $eventDispatcher, $dataProvider, $viewTransformer, $translator, $templateRenderer, $formFactory, $settingStorage);
    }

    #[\Override]
    public function listAction(Request $request): string|Response
    {
        $state = $request->query->get('state');
        if ($state === null && $request->query->has('is_active')) {
            $state = match ($request->query->getString('is_active')) {
                '0' => 'unpublished',
                '1' => 'published',
                default => 'all',
            };
        }

        $state = BlogPostListState::filterValue($state) ?? 'all';
        $request->query->set('state', $state);

        $listener = function (BeforeRenderEvent $event) use ($state): void {
            if ($event->data === null) {
                return;
            }

            $event->data['postListState'] = $state;
            $event->data['postListCounts'] = $this->stateCounts($event->data['filterData'] ?? []);
            $event->data['listContextParams'] = ['state' => $state];
            $event->data['listPrimaryActions'] = $this->canWrite() ? [[
                'url' => $this->editorUrl($this->blogUrls->main(), 'new'),
                'label' => $this->translator->trans('Write post'),
            ]] : [];
        };
        $eventName = 'adminyard.BlogPost.' . EntityConfig::EVENT_BEFORE_LIST_RENDER;
        $this->eventDispatcher->addListener($eventName, $listener);
        try {
            return parent::listAction($request);
        } finally {
            $this->eventDispatcher->removeListener($eventName, $listener);
        }
    }

    /** @return array<mixed> */
    #[\Override]
    protected function getListSorting(Request $request): array
    {
        if ($request->query->has('sort_field') && $request->query->has('sort_direction')) {
            return parent::getListSorting($request);
        }

        return ['editorial_date', \in_array($request->query->getString('state'), ['scheduled', 'overdue'], true) ? 'asc' : 'desc'];
    }

    /**
     * @param array<LogicalExpression> $filterConditions
     * @return array<array<string, mixed>>
     */
    #[\Override]
    protected function getEntityList(array $filterConditions, int $page, ?string $sortField, ?string $sortDirection): array
    {
        $sortField = $this->entityConfig->modifySortableField($sortField) ?? 'virtual_editorial_date';
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';
        $labels = DatabaseHelper::getSqlExpressionsForAssociations($this->entityConfig, FieldConfig::ACTION_LIST);
        $labels['write_access_control'] = $this->entityConfig->getWriteAccessControl() ?? LogicalExpression::true();
        $limit = $this->entityConfig->getLimit();

        return $this->dataProvider->getEntityList(
            $this->entityConfig->getTableName(),
            $this->entityConfig->getFieldDataTypes(FieldConfig::ACTION_LIST, true),
            $labels,
            array_merge(DatabaseHelper::getReadAccessControlConditions($this->entityConfig), $filterConditions),
            $sortField . ' ' . $sortDirection . ', entity.id',
            $sortDirection,
            $limit,
            $limit === null ? 0 : (max(1, $page) - 1) * $limit,
        );
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    #[\Override]
    protected function renderCellsForNormalizedRow(Request $request, array $row, string $actionForFieldRestriction): array
    {
        $canEdit = $this->canWrite() && (bool)$row['virtual_write_access_control'];
        $isPublic = $row['virtual_publication_state'] === 'published';
        $slug = (string)$row['column_slug'];
        $url = $slug !== '' && ($canEdit || $isPublic) ? $this->urls->post($slug) : null;
        $row['post_public_url'] = $url;
        $row['post_edit_url'] = $canEdit && $url !== null ? $this->editorUrl($url, 'edit') : null;

        return parent::renderCellsForNormalizedRow($request, $row, $actionForFieldRestriction);
    }

    /**
     * @param array<string, mixed> $filterData
     * @return array<string, int>
     */
    private function stateCounts(array $filterData): array
    {
        $conditions = DatabaseHelper::getReadAccessControlConditions($this->entityConfig);
        foreach ($this->entityConfig->getFilters() as $name => $filter) {
            $value = $filterData[$name] ?? null;
            if ($name !== 'state' && $value !== null && $value !== '') {
                $conditions[] = $filter->getCondition($value);
            }
        }

        $counts = [];
        $stateFilter = $this->entityConfig->getFilters()['state'];
        foreach (['all', 'draft', 'scheduled', 'published'] as $state) {
            $counts[$state] = $this->dataProvider->getEntityCount(
                $this->entityConfig->getTableName(),
                [...$conditions, $stateFilter->getCondition($state)],
            );
        }

        return $counts;
    }

    private function canWrite(): bool
    {
        return $this->permissions->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE);
    }

    private function editorUrl(string $url, string $action): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'editor=' . $action;
    }
}
