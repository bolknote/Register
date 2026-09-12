<?php

declare(strict_types = 1);

/** @var string $value */
/** @var callable $trans */

$status = in_array($value, ['published', 'scheduled', 'overdue'], true) ? $value : 'draft';
$label = match ($status) {
    'published' => 'Published',
    'scheduled' => 'Scheduled',
    'overdue' => 'Publication overdue',
    default => 'Draft',
};
?>
<span class="publication-state publication-state-<?= $status ?>"><?= htmlspecialchars($trans($label), ENT_QUOTES, 'UTF-8') ?></span>
