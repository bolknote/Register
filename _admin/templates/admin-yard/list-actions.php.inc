<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array $row */
/** @var array $rowActions */
/** @var string $csrfToken */
/** @var string $entityName */
/** @var array $primaryKey */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

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
        <a class="list-action-link list-action-link-delete" href="#"
           title="<?= $html($actionLabel) ?>"
           data-list-action="toggle-delete"><span><?= $html($actionLabel) ?></span></a>
        <span class="hidden list-action-delete-popup">
            <a class="link-as-button danger list-action-link list-action-link-delete-confirm"
               title="<?= $html($actionLabel) ?>"
               href="?<?= $html($queryParams) ?>"
               data-list-action="submit-reload"
               data-csrf-token="<?= $html($csrfToken) ?>"><?= $html($trans('Confirm deletion')) ?></a>
            <a class="link-as-button list-action-link list-action-link-delete-cancel"
               title="<?= $html($trans('Cancel')) ?>"
               href="#"
               data-list-action="cancel-delete"><?= $html($trans('Cancel')) ?></a>
        </span>
        <?php
    } else {
        ?>
        <a class="list-action-link list-action-link-<?= $html($action['name']) ?>"
           title="<?= $html($actionLabel) ?>"
           href="?<?= $html($queryParams) ?>"><span><?= $html($actionLabel) ?></span></a>
        <?php
    }
}
