<?php

declare(strict_types = 1);

/** @var array<string, mixed> $row */
/** @var string $value */
/** @var callable $trans */

$overdue = $value === 'scheduled' && (int)$row['virtual_editorial_date'] <= time();
$status = $overdue ? 'overdue' : $value;
$label = match ($status) {
    'published' => 'Published',
    'scheduled' => 'Scheduled',
    'overdue' => 'Publication overdue',
    default => 'Draft',
};
?>
<span class="publication-state publication-state-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($trans($label), ENT_QUOTES, 'UTF-8') ?>
</span>
