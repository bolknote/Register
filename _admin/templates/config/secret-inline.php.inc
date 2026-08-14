<?php

declare(strict_types=1);

/** @var callable $trans */
/** @var string $entityName */
/** @var string $fieldName */
/** @var string $value */
/** @var \S2\AdminYard\Form\Form $form */
/** @var array $primaryKey */

$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'patch',
    'field'  => $fieldName,
], $primaryKey));

$formId      = 'id-secret-' . md5(serialize($primaryKey) . $fieldName);
$isConfigured = trim($value) !== '';

?>
<span class="inline-form-holder secret-value-state" id="<?= $formId ?>-state">
    <?= $trans($isConfigured ? 'Secret configured' : 'Secret not configured') ?>
</span>
<form class="inline-form secret-inline-form" id="<?= $formId ?>" method="POST" action="?<?= $formQueryParams ?>">
    <?php foreach ($form->getHiddenControls() as $control): ?>
        <?= $control->getHtml() ?>
    <?php endforeach; ?>
    <?php foreach ($form->getVisibleControls() as $control): ?>
        <span class="input-wrapper"><?= $control->getHtml() ?></span>
    <?php endforeach; ?>
    <button type="button" class="secret-clear-button secondary" <?= $isConfigured ? '' : 'hidden' ?>>
        <?= $trans('Clear secret') ?>
    </button>
    <span class="validation-errors" aria-live="polite"></span>
</form>
<script>
    makeSecretInlineForm(<?= json_encode($formId, JSON_THROW_ON_ERROR) ?>, {
        configured: <?= json_encode($trans('Secret configured'), JSON_THROW_ON_ERROR) ?>,
        empty: <?= json_encode($trans('Secret not configured'), JSON_THROW_ON_ERROR) ?>,
        error: <?= json_encode($trans('Something went wrong and the value was not saved.'), JSON_THROW_ON_ERROR) ?>,
        clearConfirm: <?= json_encode($trans('Clear saved API key?'), JSON_THROW_ON_ERROR) ?>
    });
</script>
