<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var callable $trans */

if ($value === null || $value === '') {
    echo '—';
    return;
}

echo htmlspecialchars($trans('Spam status ' . $value), ENT_QUOTES, 'UTF-8');
