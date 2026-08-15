<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $title */
/** @var string $entityName */
/** @var array $header */
/** @var array $row */
/** @var string $csrfToken */
/** @var array $primaryKey */
/** @var array $actions */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

?>
<h1><?= $html($title) ?></h1>
<?php require __DIR__ . '/../moderation/tabs.php.inc'; ?>
<section class="show-content">
    <table class="show-table">
        <tbody>
        <?php foreach ($row['cells'] as $fieldName => $cell): ?>
            <tr>
                <td class="field-name"><?= $html($header[$fieldName]) ?></td>
                <td class="type-<?= $html($cell['type']) ?> field-<?= $html($entityName) ?>-<?= $html($fieldName) ?>"><?= $cell['content'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="show-actions">
    <?php foreach ($actions as $action): ?>
        <?php
        $queryParams = http_build_query([
            'entity' => $entityName,
            'action' => $action['name'],
            ...($action['name'] === 'edit' || $action['name'] === 'delete' ? $primaryKey : []),
        ]);
        $actionLabel = $trans($action['name']);
        ?>
        <?php if ($action['name'] === 'delete'): ?>
            <button type="button"
               class="link-as-button show-action-link show-action-link-delete danger"
               title="<?= $html($actionLabel) ?>"
               data-admin-delete
               data-delete-url="?<?= $html($queryParams) ?>"
               data-csrf-token="<?= $html($csrfToken) ?>"
               data-confirm-title="<?= $html($trans('Delete record')) ?>"
               data-confirm="<?= $html($trans('Delete record confirmation')) ?>"
               data-confirm-label="<?= $html($trans('Delete permanently')) ?>"
               data-error="<?= $html($trans('Delete record failed')) ?>"
               data-success-url="?<?= $html(http_build_query(['entity' => $entityName, 'action' => 'list'])) ?>"><span><?= $html($actionLabel) ?></span></button>
        <?php else: ?>
            <a class="link-as-button show-action-link show-action-link-<?= $html($action['name']) ?>"
               title="<?= $html($actionLabel) ?>"
               href="?<?= $html($queryParams) ?>"><span><?= $html($actionLabel) ?></span></a>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
