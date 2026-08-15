<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array $row */
/** @var array $rowActions */
/** @var string $csrfToken */
/** @var string $entityName */
/** @var array $primaryKey */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ((bool)$row['virtual_write_access_control']) {
    ?><span hidden data-bulk-row-permitted></span><?php
}

foreach ($rowActions as $action) {
    if (!$row['virtual_write_access_control'] && ($action['name'] === 'edit' || $action['name'] === 'delete')) {
        continue;
    }

    $queryParams = http_build_query(array_merge([
        'entity' => $entityName,
        'action' => $action['name'],
    ], $primaryKey));
    $actionLabel = $trans($action['name']);

    if ($action['name'] === 'delete') {
        ?>
        <button type="button"
                class="list-action-link list-action-link-delete"
                title="<?= $html($actionLabel) ?>"
                data-admin-delete
                data-delete-url="?<?= $html($queryParams) ?>"
                data-csrf-token="<?= $html($csrfToken) ?>"
                data-confirm-title="<?= $html($trans('Delete record')) ?>"
                data-confirm="<?= $html($trans('Delete record confirmation')) ?>"
                data-confirm-label="<?= $html($trans('Delete permanently')) ?>"
                data-error="<?= $html($trans('Delete record failed')) ?>"><span><?= $html($actionLabel) ?></span></button>
        <?php
    } else {
        ?>
        <a class="list-action-link list-action-link-<?= $html($action['name']) ?>"
           title="<?= $html($actionLabel) ?>"
           href="?<?= $html($queryParams) ?>"><span><?= $html($actionLabel) ?></span></a>
        <?php
    }
}
