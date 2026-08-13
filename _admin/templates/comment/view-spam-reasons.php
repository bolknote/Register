<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var callable $trans */

if ($value === null || $value === '') {
    echo '<span class="null">null</span>';
    return;
}

try {
    $reasons = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    echo '<span class="null">invalid</span>';
    return;
}

if (!\is_array($reasons) || $reasons === []) {
    echo '—';
    return;
}

uasort($reasons, static fn(mixed $left, mixed $right): int => abs((int)$right) <=> abs((int)$left));
$reasons = array_slice($reasons, 0, 3, true);

$output = [];
foreach ($reasons as $reason => $weight) {
    if (!\is_string($reason)) {
        continue;
    }

    $label = str_starts_with($reason, 'rule_')
        ? $trans('Manual rule') . ' #' . substr($reason, 5)
        : $trans('Spam reason ' . $reason);
    $integerWeight = (int)$weight;
    $formattedWeight = $integerWeight >= 0 ? '+' . $integerWeight : (string)$integerWeight;
    $output[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '&nbsp;' . $formattedWeight;
}

echo implode('<br>', $output);
