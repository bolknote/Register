<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var callable $trans */

if ($value === null || $value === '') {
    echo '—';
    return;
}

try {
    $reasons = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    echo '—';
    return;
}

if (!\is_array($reasons) || $reasons === []) {
    echo '—';
    return;
}

uasort($reasons, static fn(mixed $left, mixed $right): int => abs((int)$right) <=> abs((int)$left));

$output = [];
foreach ($reasons as $reason => $weight) {
    if (!\is_string($reason)) {
        continue;
    }

    $translationKey = 'Spam reason ' . $reason;
    $label = str_starts_with($reason, 'rule_')
        ? $trans('Manual rule') . ' #' . substr($reason, 5)
        : $trans($translationKey);
    if ($label === $translationKey) {
        $label = $trans('Other spam signal');
    }
    $integerWeight = (int)$weight;
    $formattedWeight = $integerWeight >= 0 ? '+' . $integerWeight : (string)$integerWeight;
    $output[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '&nbsp;' . $formattedWeight;
}

echo implode('<br>', $output);
