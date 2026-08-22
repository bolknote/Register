<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $entityName */
/** @var string $fieldName */
/** @var string $value */
/** @var \Register\AdminYard\Form\Form $form */
/** @var array $primaryKey */

$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => 'patch',
    'field'  => $fieldName,
], $primaryKey));
$formId   = 'id-' . md5(serialize($primaryKey) . $fieldName);
$holderId = $formId . '-' . $fieldName;
$labelId  = $formId . '-accessible-label';
$html     = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$fieldLabel = $trans(ucfirst(str_replace('_', ' ', $fieldName)));
$rowLabel   = implode(', ', array_map(
    static fn(string $key, mixed $value): string => $key . ' ' . (string)$value,
    array_keys($primaryKey),
    $primaryKey,
));
$accessibleLabel = sprintf($trans('Inline edit control'), $fieldLabel, $rowLabel);

?>
<span class="inline-form-holder" id="<?= $holderId ?>" aria-live="polite"><?= $html($value) ?></span>
<form class="inline-form" id="<?= $formId ?>" method="POST" action="?<?= $html($formQueryParams) ?>"
      data-inline-form
      data-error-message="<?= $html($trans('Something went wrong and the value was not saved.')) ?>">
    <span class="visually-hidden" id="<?= $labelId ?>"><?= $html($accessibleLabel) ?></span>
    <?php foreach ($form->getHiddenControls() as $control): ?>
        <?= $control->getHtml() ?>
    <?php endforeach; ?>
    <?php foreach ($form->getVisibleControls() as $visibleFieldName => $control): ?>
        <?php
        $controlHtml = $control->getHtml($formId . '-control');
        $controlHtml = preg_replace(
            '/<(input|select|textarea|button)\b/',
            '<$1 aria-labelledby="' . $labelId . '"',
            $controlHtml,
        ) ?? $controlHtml;
        ?>
        <div
            class="form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $html($entityName) ?>-<?= $html($visibleFieldName) ?>">
            <span class="input-wrapper"><?= $controlHtml ?></span>
            <span class="validation-errors"></span>
        </div>
    <?php endforeach; ?>
</form>
