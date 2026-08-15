<?php

declare(strict_types = 1);

use S2\AdminYard\Form\Autocomplete;
use S2\AdminYard\Form\CheckboxArray;
use S2\AdminYard\Form\MultiSelect;
use S2\AdminYard\Form\Radio;

/** @var callable $trans */
/** @var string $title */
/** @var string $entityName */
/** @var array<string, \S2\AdminYard\Form\FormControlInterface> $filterControls */
/** @var array<string, string> $filterLabels */
/** @var array<string, mixed> $filterData */
/** @var list<string> $sortableFields */
/** @var string|null $sortField */
/** @var string|null $sortDirection */
/** @var array<string, string> $header */
/** @var array<string, string> $hint */
/** @var list<array<mixed>> $rows */
/** @var list<array{name: string}> $entityActions */
/** @var int|null $totalCount */
/** @var int $page */
/** @var int|null $limit */
/** @var list<array{id: string, name: string, state: array{filters: array<string, mixed>, sort_field: string|null, sort_direction: string|null}}> $savedListViews */
/** @var array{filters: array<string, mixed>, sort_field: string|null, sort_direction: string|null} $savedListViewState */
/** @var string $savedListViewCsrfToken */
/** @var string|null $activeSavedListViewId */
/** @var list<string> $bulkListActions */
/** @var string $bulkListCsrfToken */

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

/**
 * Generates pagination only when navigation between pages is possible.
 *
 * @param array<string, mixed> $queryParams
 */
$renderPagination = static function (
    int $page,
    ?int $limit,
    ?int $totalCount,
    array $queryParams = [],
) use ($html, $trans): string {
    $page = max(1, $page);
    if ($limit === null) {
        return '';
    }

    $totalPages = $totalCount !== null ? max(1, (int)ceil($totalCount / $limit)) : $page + 1;
    if ($totalPages <= 1) {
        return '';
    }

    $paginationHtml = '<nav class="pagination" aria-label="' . $html($trans('Pagination')) . '">';
    $range          = 2;
    $previousPage   = 0;
    for ($pageNumber = 1; $pageNumber <= $totalPages; ++$pageNumber) {
        if ($pageNumber !== 1 && $pageNumber !== $totalPages && abs($pageNumber - $page) > $range) {
            continue;
        }
        if ($previousPage > 0 && $pageNumber > $previousPage + 1) {
            $paginationHtml .= '<span class="pagination-ellipsis" aria-hidden="true">…</span>';
        }

        $queryParams['page'] = $pageNumber;
        if ($pageNumber === $page) {
            $paginationHtml .= '<span class="pagination-current" aria-current="page">' . $pageNumber . '</span>';
        } else {
            $paginationHtml .= '<a class="pagination-link" href="?' . $html(http_build_query($queryParams)) . '">' . $pageNumber . '</a>';
        }
        $previousPage = $pageNumber;
    }

    return $paginationHtml . '</nav>';
};

$hasActions = false;
foreach ($rows as $row) {
    if (($row['rendered_actions'] ?? '') !== '') {
        $hasActions = true;
        break;
    }
}

$hasBulkSelectableRows = false;
if (isset($bulkListActions, $bulkListCsrfToken) && $bulkListActions !== []) {
    foreach ($rows as $row) {
        if (str_contains((string)($row['rendered_actions'] ?? ''), 'data-bulk-row-permitted')) {
            $hasBulkSelectableRows = true;
            break;
        }
    }
}

$visibleFilterData = array_intersect_key($filterData, $filterControls);
$activeFilterCount = count(array_filter(
    $visibleFilterData,
    static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== false,
));
$hasFilterErrors = false;
foreach ($filterControls as $control) {
    if ($control->getValidationErrors() !== []) {
        $hasFilterErrors = true;
        break;
    }
}

$resetFilterParams = [
    'entity'       => $entityName,
    'action'       => 'list',
    'apply_filter' => '1',
];
foreach ($filterControls as $fieldName => $control) {
    if (!$control instanceof MultiSelect) {
        $resetFilterParams[$fieldName] = '';
    }
}

?>
<section class="list-header">
    <h1><?= $html($title) ?></h1>
    <?php if ($entityActions !== []): ?>
        <div class="list-header-actions">
            <?php foreach ($entityActions as $action): ?>
                <a class="link-as-button entity-action entity-action-<?= $html($action['name']) ?>"
                   href="?<?= $html(http_build_query(['entity' => $entityName, 'action' => $action['name']])) ?>"
                ><?= $html($trans($action['name'])) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php if ($entityName === 'Article'): ?>
    <nav class="section-tabs" aria-label="<?= $html($trans('Page display modes')) ?>">
        <a href="?entity=Article&action=list" aria-current="page"><?= $html($trans('List view')) ?></a>
        <a href="?entity=Site"><?= $html($trans('Structure view')) ?></a>
    </nav>
<?php endif; ?>
<?php require __DIR__ . '/../moderation/tabs.php.inc'; ?>
<?php if (isset($listIntroHtml) && \is_string($listIntroHtml) && $listIntroHtml !== ''): ?>
    <?= $listIntroHtml ?>
<?php endif; ?>
<?php if ($filterControls !== [] && isset($savedListViews, $savedListViewState, $savedListViewCsrfToken)): ?>
    <?php $savedViewStateJson = json_encode($savedListViewState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); ?>
    <section class="saved-list-views"
             data-saved-list-views
             data-ajax-url="<?= $html($basePath . '/_admin/ajax.php') ?>"
             data-entity="<?= $html($entityName) ?>"
             data-csrf-token="<?= $html($savedListViewCsrfToken) ?>"
             data-current-state="<?= $html($savedViewStateJson) ?>"
             data-saving-label="<?= $html($trans('Saving saved view')) ?>"
             data-error-label="<?= $html($trans('Unable to update saved views')) ?>">
        <div class="saved-list-views-summary">
            <strong><?= $html($trans('Saved views')) ?></strong>
            <?php if ($savedListViews === []): ?>
                <span class="saved-list-views-empty"><?= $html($trans('No saved views yet')) ?></span>
            <?php else: ?>
                <nav class="saved-list-views-links" aria-label="<?= $html($trans('Saved views')) ?>">
                    <?php foreach ($savedListViews as $savedView): ?>
                        <?php
                        $savedViewParams = [
                            'entity'       => $entityName,
                            'action'       => 'list',
                            'apply_filter' => '1',
                            ...$savedView['state']['filters'],
                            ...($savedView['state']['sort_field'] !== null ? [
                                'sort_field'     => $savedView['state']['sort_field'],
                                'sort_direction' => $savedView['state']['sort_direction'] ?? 'asc',
                            ] : []),
                        ];
                        $isActiveSavedView = $savedView['id'] === $activeSavedListViewId;
                        ?>
                        <span class="saved-list-view <?= $isActiveSavedView ? 'is-active' : '' ?>">
                            <a href="?<?= $html(http_build_query($savedViewParams)) ?>"
                               <?= $isActiveSavedView ? 'aria-current="page"' : '' ?>><?= $html($savedView['name']) ?></a>
                            <button type="button"
                                    class="saved-list-view-delete"
                                    data-saved-list-view-delete
                                    data-view-id="<?= $html($savedView['id']) ?>"
                                    aria-label="<?= $html(sprintf($trans('Delete saved view'), $savedView['name'])) ?>">×</button>
                        </span>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
        <form class="saved-list-view-form" data-saved-list-view-form>
            <label for="saved-list-view-name-<?= $html($entityName) ?>"><?= $html($trans('Saved view name')) ?></label>
            <input id="saved-list-view-name-<?= $html($entityName) ?>"
                   name="name"
                   type="text"
                   maxlength="80"
                   autocomplete="off"
                   required
                   placeholder="<?= $html($trans('Saved view name')) ?>">
            <button class="secondary" type="submit"><?= $html($trans('Save current view')) ?></button>
            <span class="saved-list-view-status" data-saved-list-view-status aria-live="polite"></span>
        </form>
    </section>
<?php endif; ?>
<?php if ($filterControls !== []): ?>
    <details class="filter-panel" <?= $activeFilterCount > 0 || $hasFilterErrors ? 'open' : '' ?>>
        <summary>
            <span><?= $html($trans('Filters')) ?></span>
            <?php if ($activeFilterCount > 0): ?>
                <span class="filter-count" aria-label="<?= $html($trans('Active filters')) ?>"><?= $activeFilterCount ?></span>
            <?php endif; ?>
        </summary>
        <section class="filter-content">
            <form method="GET" action="?" aria-label="<?= $html($trans('Filters')) ?>">
                <input type="hidden" name="entity" value="<?= $html($entityName) ?>">
                <input type="hidden" name="action" value="list">
                <div class="filter-controls">
                    <?php foreach ($filterControls as $fieldName => $control): ?>
                        <?php
                        $controlClass = strtolower(basename(strtr(get_class($control), ['\\' => '/'])));
                        $safeName     = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $entityName . '-' . $fieldName) ?? '';
                        $controlId    = 'filter-' . $safeName;
                        $label        = $filterLabels[$fieldName] ?? $header[$fieldName] ?? $fieldName;
                        $grouped      = $control instanceof Radio || $control instanceof CheckboxArray;
                        ?>
                        <?php if ($grouped): ?>
                            <fieldset class="filter-control filter-control-<?= $html($controlClass) ?> filter-<?= $html($safeName) ?>">
                                <legend class="filter-label"><?= $html($label) ?></legend>
                                <span class="filter-wrapper"><?= $control->getHtml() ?></span>
                                <?php foreach ($control->getValidationErrors() as $error): ?>
                                    <span class="filter-validation-error validation-error"><?= $html($error) ?></span>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php else: ?>
                            <div class="filter-control filter-control-<?= $html($controlClass) ?> filter-<?= $html($safeName) ?>">
                                <label class="filter-label" id="<?= $html($controlId) ?>-label" for="<?= $html($controlId) ?>"><?= $html($label) ?></label>
                                <span class="filter-wrapper">
                                    <?php
                                    $controlHtml = $control->getHtml($controlId);
                                    if ($control instanceof Autocomplete) {
                                        $controlHtml = preg_replace(
                                            '/<button\b/',
                                            '<button aria-labelledby="' . $html($controlId) . '-label"',
                                            $controlHtml,
                                            1,
                                        ) ?? $controlHtml;
                                    }
                                    echo $controlHtml;
                                    ?>
                                </span>
                                <?php foreach ($control->getValidationErrors() as $error): ?>
                                    <span class="filter-validation-error validation-error"><?= $html($error) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="filter-actions">
                        <button class="secondary filter-button" type="submit" name="apply_filter" value="1">
                            <?= $html($trans('Filter')) ?>
                        </button>
                        <?php if ($activeFilterCount > 0): ?>
                            <a href="?<?= $html(http_build_query($resetFilterParams)) ?>"><?= $html($trans('Reset filters')) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </details>
<?php endif; ?>
<section class="list-content">
    <?php if ($hasBulkSelectableRows): ?>
        <section class="bulk-list-toolbar"
                 data-bulk-list
                 data-ajax-url="<?= $html($basePath . '/_admin/ajax.php') ?>"
                 data-entity="<?= $html($entityName) ?>"
                 data-csrf-token="<?= $html($bulkListCsrfToken) ?>"
                 data-selected-label="<?= $html($trans('Bulk selected count')) ?>"
                 data-running-label="<?= $html($trans('Bulk action running')) ?>"
                 data-error-label="<?= $html($trans('Bulk action failed')) ?>"
                 data-confirm-title="<?= $html($trans('Delete selected items')) ?>"
                 data-confirm-label="<?= $html($trans('Delete permanently')) ?>"
                 data-confirm-message="<?= $html($trans('Bulk delete confirmation')) ?>">
            <strong data-bulk-selection-count><?= $html(str_replace('{{ count }}', '0', $trans('Bulk selected count'))) ?></strong>
            <label for="bulk-list-action-<?= $html($entityName) ?>"><?= $html($trans('Bulk action')) ?></label>
            <select id="bulk-list-action-<?= $html($entityName) ?>" data-bulk-action>
                <option value=""><?= $html($trans('Choose bulk action')) ?></option>
                <?php foreach ($bulkListActions as $bulkListAction): ?>
                    <option value="<?= $html($bulkListAction) ?>"
                            <?= $bulkListAction === 'delete' ? 'data-dangerous' : '' ?>><?= $html($trans('Bulk action ' . $bulkListAction)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="secondary" data-bulk-apply disabled><?= $html($trans('Apply bulk action')) ?></button>
            <span class="bulk-list-status" data-bulk-status aria-live="polite"></span>
        </section>
    <?php endif; ?>
    <div class="table-wrapper">
        <table class="list-table" aria-label="<?= $html($title) ?>">
            <thead>
            <tr>
                <?php if ($hasBulkSelectableRows): ?>
                    <th scope="col" class="bulk-selection-column">
                        <input type="checkbox"
                               data-bulk-select-all
                               aria-label="<?= $html($trans('Select all rows')) ?>">
                    </th>
                <?php endif; ?>
                <?php foreach ($header as $fieldName => $cell): ?>
                    <?php if (in_array($fieldName, $sortableFields, true)): ?>
                        <th scope="col"
                            title="<?= $html($hint[$fieldName] ?? '') ?>"
                            class="field-<?= $html($entityName) ?>-<?= $html($fieldName) ?> <?= $fieldName === $sortField ? 'current-sort' : '' ?>">
                            <a class="sort-link"
                               href="?<?= $html(http_build_query(['entity' => $entityName, 'action' => 'list', ...$filterData, 'sort_field' => $fieldName, 'sort_direction' => $sortDirection === 'asc' && $fieldName === $sortField ? 'desc' : 'asc'])) ?>">
                                <?= $html($cell) ?><?= $fieldName === $sortField ? ($sortDirection === 'asc' ? ' ▴' : ' ▾') : '' ?>
                            </a>
                        </th>
                    <?php else: ?>
                        <th scope="col"
                            title="<?= $html($hint[$fieldName] ?? '') ?>"
                            class="field-<?= $html($entityName) ?>-<?= $html($fieldName) ?>"><?= $html($cell) ?></th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($hasActions): ?>
                    <th scope="col"><?= $html($trans('Actions')) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $rowIndex => $row): ?>
                <?php $rowIsBulkSelectable = $hasBulkSelectableRows && str_contains((string)($row['rendered_actions'] ?? ''), 'data-bulk-row-permitted'); ?>
                <tr <?= $rowIsBulkSelectable ? 'data-bulk-row' : '' ?>>
                    <?php if ($hasBulkSelectableRows): ?>
                        <td class="bulk-selection-column">
                            <?php if ($rowIsBulkSelectable): ?>
                                <input type="checkbox"
                                       data-bulk-row-select
                                       data-primary-key="<?= $html(json_encode($row['primary_key'], JSON_THROW_ON_ERROR)) ?>"
                                       data-row-csrf-token="<?= $html((string)($row['csrf_token'] ?? '')) ?>"
                                       aria-label="<?= $html(sprintf($trans('Select row'), $rowIndex + 1)) ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php foreach ($row['cells'] as $fieldName => $cell): ?>
                        <td class="type-<?= $html($cell['type']) ?> field-<?= $html($entityName) ?>-<?= $html($fieldName) ?> <?= $fieldName === $sortField ? 'current-sort' : '' ?>"><?= $cell['content'] ?></td>
                    <?php endforeach; ?>
                    <?php if ($hasActions): ?>
                        <td class="row-actions"><?= $row['rendered_actions'] ?? '' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr class="empty-state-row">
                    <td colspan="<?= count($header) + ($hasActions ? 1 : 0) + ($hasBulkSelectableRows ? 1 : 0) ?>">
                        <strong><?= $html($trans($activeFilterCount > 0 ? 'No matching results' : 'No items yet')) ?></strong>
                        <?php if ($activeFilterCount > 0): ?>
                            <a href="?<?= $html(http_build_query($resetFilterParams)) ?>"><?= $html($trans('Reset filters')) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $paginationParams = [
        'entity' => $entityName,
        'action' => 'list',
        ...$filterData,
        ...($sortField !== null ? ['sort_field' => $sortField, 'sort_direction' => $sortDirection] : []),
    ];
    echo $renderPagination($page, $limit, $totalCount, $paginationParams);
    ?>
</section>
