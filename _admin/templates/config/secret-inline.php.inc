<?php

declare(strict_types=1);

/** @var callable $trans */
/** @var string $entityName */
/** @var string $fieldName */
/** @var string $value */
/** @var \S2\AdminYard\Form\Form $form */
/** @var array $primaryKey */
/** @var string $formId */
/** @var string $controlId */
/** @var string $helpId */
/** @var string $statusId */
/** @var bool $isApplied */

$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'patch',
    'field'  => $fieldName,
], $primaryKey));

$isConfigured = trim($value) !== '';
$html         = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

?>
<span class="inline-form-holder secret-value-state" id="<?= $html($formId) ?>-state">
    <?= $html($trans($isConfigured ? 'Secret configured' : 'Secret not configured')) ?>
</span>
<form class="inline-form secret-inline-form" id="<?= $html($formId) ?>" method="POST" action="?<?= $html($formQueryParams) ?>"
      data-secret-inline-form
      data-config-key="<?= $html((string)($primaryKey['name'] ?? '')) ?>"
      data-configured-message="<?= $html($trans('Secret configured')) ?>"
      data-empty-message="<?= $html($trans('Secret not configured')) ?>"
      data-saving-message="<?= $html($trans('Saving setting')) ?>"
      data-saved-message="<?= $html($trans('Setting applied')) ?>"
      data-dirty-message="<?= $html($trans('Setting not saved')) ?>"
      data-error-message="<?= $html($trans('Something went wrong and the value was not saved.')) ?>"
      data-password-required-message="<?= $html($trans('Enter current password')) ?>"
      data-clear-confirm-message="<?= $html($trans('Clear saved API key?')) ?>">
    <?php foreach ($form->getHiddenControls() as $control): ?>
        <?= $control->getHtml() ?>
    <?php endforeach; ?>
    <div class="secret-value-row">
        <?php foreach ($form->getVisibleControls() as $control): ?>
            <?php
            $controlHtml = $control->getHtml($controlId);
            $controlHtml = preg_replace(
                '/<(input)\b/',
                '<$1 aria-describedby="' . $html($helpId . ' ' . $statusId) . '"',
                $controlHtml,
                1,
            ) ?? $controlHtml;
            ?>
            <span class="input-wrapper"><?= $controlHtml ?></span>
        <?php endforeach; ?>
        <button type="submit" class="secret-save-button"><?= $html($trans('Save')) ?></button>
        <button type="button" class="secret-clear-button secondary" <?= $isConfigured ? '' : 'hidden' ?>>
            <?= $html($trans('Clear secret')) ?>
        </button>
    </div>
    <label class="secret-reauth-field">
        <span><?= $html($trans('Current password')) ?></span>
        <input type="password" name="current_password" value="" autocomplete="current-password"
               maxlength="255" aria-describedby="<?= $html($statusId) ?>" required>
    </label>
    <span class="validation-errors" aria-live="polite"></span>
    <span class="config-save-state" id="<?= $html($statusId) ?>" data-config-save-state
          data-state="<?= $isApplied ? 'applied' : 'pending' ?>" aria-live="polite">
        <?= $html($trans($isApplied ? 'Setting applied' : 'Setting saved but not applied')) ?>
    </span>
</form>
