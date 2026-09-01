<?php

declare(strict_types = 1);

/** @var string $value */
/** @var callable $trans */

if (trim($value) === '') {
    $value = $trans('Unnamed user');
}

require __DIR__ . '/../admin-yard/inline_form_cell.php.inc';
