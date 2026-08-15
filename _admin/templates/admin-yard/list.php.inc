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
    <nav class="section-tabs" aria-label="<?= $html($trans('Page views')) ?>">
        <a href="?entity=Article&action=list" aria-current="page"><?= $html($trans('List view')) ?></a>
        <a href="?entity=Site"><?= $html($trans('Structure view')) ?></a>
    </nav>
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
    <div class="table-wrapper">
        <table class="list-table" aria-label="<?= $html($title) ?>">
            <thead>
            <tr>
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
            <?php foreach ($rows as $row): ?>
                <tr>
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
                    <td colspan="<?= count($header) + ($hasActions ? 1 : 0) ?>">
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
