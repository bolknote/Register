<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $entityName */
/** @var string $fieldName */
/** @var string $value */
/** @var \Register\AdminYard\Form\Form $form */
/** @var array<string, scalar> $primaryKey */
/** @var string $formId */
/** @var string $controlId */
/** @var string $helpId */
/** @var string $statusId */
/** @var bool $isApplied */

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'patch',
    'field'  => $fieldName,
], $primaryKey));
$holderId = $formId . '-' . $fieldName;

?>
<span class="inline-form-holder" id="<?= $html($holderId) ?>" aria-hidden="true"><?= $html($value) ?></span>
<form class="inline-form config-inline-form" id="<?= $html($formId) ?>" method="POST"
      action="?<?= $html($formQueryParams) ?>"
      data-inline-form
      data-config-key="<?= $html((string)($primaryKey['name'] ?? '')) ?>"
      data-saving-message="<?= $html($trans('Saving setting')) ?>"
      data-saved-message="<?= $html($trans('Setting applied')) ?>"
      data-dirty-message="<?= $html($trans('Setting not saved')) ?>"
      data-error-message="<?= $html($trans('Something went wrong and the value was not saved.')) ?>">
    <?php foreach ($form->getHiddenControls() as $control): ?>
        <?= $control->getHtml() ?>
    <?php endforeach; ?>
    <?php foreach ($form->getVisibleControls() as $visibleFieldName => $control): ?>
        <?php
        $controlHtml = $control->getHtml($controlId);
        $controlHtml = preg_replace(
            '/<(input|select|textarea)\b/',
            '<$1 aria-describedby="' . $html($helpId . ' ' . $statusId) . '"',
            $controlHtml,
            1,
        ) ?? $controlHtml;
        ?>
        <span class="input-wrapper field-<?= $html($entityName) ?>-<?= $html($visibleFieldName) ?>"><?= $controlHtml ?></span>
    <?php endforeach; ?>
    <span class="validation-errors" aria-live="polite"></span>
    <span class="config-save-state" id="<?= $html($statusId) ?>" data-config-save-state
          data-state="<?= $isApplied ? 'applied' : 'pending' ?>" aria-live="polite">
        <?= $html($trans($isApplied ? 'Setting applied' : 'Setting saved but not applied')) ?>
    </span>
</form>
