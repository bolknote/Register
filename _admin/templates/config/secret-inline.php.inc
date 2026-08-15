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
<form class="inline-form secret-inline-form" id="<?= $formId ?>" method="POST" action="?<?= $formQueryParams ?>"
      data-secret-inline-form
      data-configured-message="<?= htmlspecialchars($trans('Secret configured'), ENT_QUOTES, 'UTF-8') ?>"
      data-empty-message="<?= htmlspecialchars($trans('Secret not configured'), ENT_QUOTES, 'UTF-8') ?>"
      data-error-message="<?= htmlspecialchars($trans('Something went wrong and the value was not saved.'), ENT_QUOTES, 'UTF-8') ?>"
      data-clear-confirm-message="<?= htmlspecialchars($trans('Clear saved API key?'), ENT_QUOTES, 'UTF-8') ?>">
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
