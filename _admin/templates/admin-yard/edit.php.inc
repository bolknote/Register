<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $title */
/** @var string $entityName */
/** @var array $header */
/** @var array $hint */
/** @var array $errorMessages */
/** @var \S2\AdminYard\Form\Form $form */
/** @var string $csrfToken */
/** @var array $primaryKey */
/** @var array $actions */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'edit',
], $primaryKey));
$errorMessages = array_merge($errorMessages, $form->getGlobalFormErrors());

?>
<h1><?= $html($title) ?></h1>
<?php require __DIR__ . '/../moderation/tabs.php.inc'; ?>
<section class="edit-content">
    <?php if ($errorMessages !== []): ?>
        <div class="error-message-box">
            <p><?php printf($trans('Cannot save %s due to the following errors:'), $html($entityName)) ?></p>
            <ul class="error-messages">
                <?php foreach ($errorMessages as $message): ?>
                    <li><?= $html($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="?<?= $html($formQueryParams) ?>">
        <?php foreach ($form->getHiddenControls() as $control): ?>
            <?= $control->getHtml() ?>
        <?php endforeach; ?>
        <div class="form-wrapper">
            <?php foreach ($form->getVisibleControls() as $fieldName => $control): ?>
                <label class="field-name"
                       title="<?= $html($hint[$fieldName] ?? '') ?>"
                       for="id-<?= $html($fieldName) ?>"><?= $html($header[$fieldName]) ?></label>
                <div
                    class="form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $html($entityName) ?>-<?= $html($fieldName) ?>">
                    <?= $control->getHtml('id-' . $fieldName) ?>
                    <?php foreach ($control->getValidationErrors() as $error): ?>
                        <span class="validation-error"><?= $html($error) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-buttons">
                <button type="submit"><?= $html($trans('Save')) ?></button>
                <span>
                <?php foreach ($actions as $action): ?>
                    <?php
                    $queryParams = http_build_query([
                        'entity' => $entityName,
                        'action' => $action['name'],
                        ...($action['name'] === 'show' || $action['name'] === 'delete' ? $primaryKey : []),
                    ]);
                    $actionLabel = $trans($action['name']);
                    ?>
                    <?php if ($action['name'] === 'delete'): ?>
                        <button type="button"
                           class="link-as-button edit-action-link edit-action-link-delete danger"
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
                        <a class="link-as-button edit-action-link edit-action-link-<?= $html($action['name']) ?>"
                           title="<?= $html($actionLabel) ?>"
                           href="?<?= $html($queryParams) ?>"><span><?= $html($actionLabel) ?></span></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                </span>
            </div>
        </div>
    </form>
</section>
