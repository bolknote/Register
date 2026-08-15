<?php

declare(strict_types = 1);

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
$formId   = 'id-' . md5(serialize($primaryKey) . $fieldName);
$holderId = $formId . '-' . $fieldName;
$html     = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

?>
<span class="inline-form-holder" id="<?= $holderId ?>"><?= $html($value) ?></span>
<form class="inline-form" id="<?= $formId ?>" method="POST" action="?<?= $html($formQueryParams) ?>"
      data-inline-form
      data-error-message="<?= $html($trans('Something went wrong and the value was not saved.')) ?>">
    <?php foreach ($form->getHiddenControls() as $control): ?>
        <?= $control->getHtml() ?>
    <?php endforeach; ?>
    <?php foreach ($form->getVisibleControls() as $visibleFieldName => $control): ?>
        <div
            class="form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $html($entityName) ?>-<?= $html($visibleFieldName) ?>">
            <span class="input-wrapper"><?= $control->getHtml() ?></span>
            <span class="validation-errors"></span>
        </div>
    <?php endforeach; ?>
</form>
