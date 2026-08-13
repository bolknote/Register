<?php

/** @var callable $trans */
/** @var callable $isGranted */
/** @var array $row */
/** @var array $rowActions */
/** @var string $csrfToken */
/** @var string $entityName */
/** @var array $primaryKey */

$spamLabel = (string)($row['column_spam_label'] ?? '');
if ($isGranted(\S2\Cms\Model\PermissionChecker::PERMISSION_HIDE_COMMENTS)) {
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
           onclick="fetch(this.href, {method: 'POST', body: new URLSearchParams('csrf_token=<?= $csrfToken ?>') }).then(function () { window.location.reload(); }); return false;"
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

if (!$row['column_sent'] && !$row['column_shown'] && $isGranted(\S2\Cms\Model\PermissionChecker::PERMISSION_HIDE_COMMENTS)) {
?>
    <a class="list-action-link list-action-link-reject"
       onclick="fetch(this.href, {method: 'POST', body: new URLSearchParams('csrf_token=<?= $csrfToken ?>') }).then(() => { this.parentNode.removeChild(this); } ); return false;"
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
        <a class="list-action-link list-action-link-<?= $action['name'] ?>" href="#"
           title="<?= $actionTitle ?>"
           onclick="this.parentNode.querySelector('span.list-action-delete-popup').classList.toggle('hidden'); return false"><span><?= $actionName ?></span></a>
        <span class="hidden list-action-delete-popup">
            <a class="link-as-button danger list-action-link list-action-link-delete-confirm"
               title="<?= $actionName ?>"
               href="?<?= $queryParams ?>"
               onclick="fetch(this.href, {method: 'POST', body: new URLSearchParams('csrf_token=<?= $csrfToken ?>') }).then(function () { window.location.reload(); } ); return false;"><?= $trans('Confirm deletion') ?></a>
            <a class="link-as-button list-action-link list-action-link-delete-cancel"
               title="<?= $trans('Cancel') ?>"
               href="#"
               onclick="this.parentNode.classList.toggle('hidden'); return false;"><?= $trans('Cancel') ?></a>
        </span>
        <?php
    } else {
        ?>
        <a class="list-action-link list-action-link-<?= $action['name'] ?>"
           title="<?= $actionTitle ?>"
           href="?<?= $queryParams ?>"><span><?= $actionName ?></span></a>
        <?php
    }
}
