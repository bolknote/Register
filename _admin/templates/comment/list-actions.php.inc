<?php

/** @var callable $trans */
/** @var callable $isGranted */
/** @var array $row */
/** @var array $rowActions */
/** @var string $csrfToken */
/** @var string $entityName */
/** @var array $primaryKey */

if ((bool)$row['virtual_write_access_control']) {
    ?><span hidden data-bulk-row-permitted></span><?php
}

$spamLabel = (string)($row['column_spam_label'] ?? '');
if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_HIDE_COMMENTS)) {
    foreach ([
                 'ham'  => ['label' => $trans('Not spam'), 'title' => $trans('Publish and learn as ham')],
                 'spam' => ['label' => $trans('Spam'), 'title' => $trans('Hide and learn as spam')],
             ] as $feedbackAction => $feedback) {
        if ($spamLabel === $feedbackAction) {
            continue;
        }

        $feedbackParams = http_build_query(array_merge([
            'entity' => $entityName,
            'action' => $feedbackAction,
        ], $primaryKey));
        ?>
        <a class="list-action-link list-action-link-<?= $feedbackAction ?>"
           data-list-action="submit-reload"
           data-csrf-token="<?= register_htmlencode($csrfToken) ?>"
           title="<?= $feedback['title'] ?>"
           href="?<?= $feedbackParams ?>"><span><?= $feedback['label'] ?></span></a>
        <?php
    }
}

$actionName = $trans('reject');
$actionTitle = $trans('Leave hidden');

$queryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'reject',
], $primaryKey));

if (!$row['column_sent'] && !$row['column_shown'] && $isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_HIDE_COMMENTS)) {
?>
    <a class="list-action-link list-action-link-reject"
       data-list-action="submit-remove"
       data-csrf-token="<?= register_htmlencode($csrfToken) ?>"
       title="<?= $actionTitle ?>"
       href="?<?= $queryParams ?>"><span><?= $actionName ?></span></a>
    <?php
}

foreach ($rowActions as $action) {
    $queryParams = http_build_query(array_merge([
        'entity' => $entityName,
        'action' => $action['name']
    ], $primaryKey));

    $actionName = $trans($action['name']);
    $actionTitle = $trans($action['name']);

    if ($action['name'] === 'delete') {
        ?>
        <button type="button"
                class="list-action-link list-action-link-delete"
                title="<?= register_htmlencode($actionTitle) ?>"
                data-admin-delete
                data-delete-url="?<?= register_htmlencode($queryParams) ?>"
                data-csrf-token="<?= register_htmlencode($csrfToken) ?>"
                data-confirm-title="<?= register_htmlencode($trans('Delete record')) ?>"
                data-confirm="<?= register_htmlencode($trans('Delete record confirmation')) ?>"
                data-confirm-label="<?= register_htmlencode($trans('Delete permanently')) ?>"
                data-error="<?= register_htmlencode($trans('Delete record failed')) ?>"><span><?= register_htmlencode($actionName) ?></span></button>
        <?php
    } else {
        ?>
        <a class="list-action-link list-action-link-<?= $action['name'] ?>"
           title="<?= $actionTitle ?>"
           href="?<?= $queryParams ?>"><span><?= $actionName ?></span></a>
        <?php
    }
}
