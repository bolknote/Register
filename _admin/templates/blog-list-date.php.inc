<?php

declare(strict_types = 1);

/** @var array<string, mixed> $row */
/** @var int|string $value */
/** @var callable $trans */

$timestamp = (int)$value;
$dateLabel = match ($row['virtual_publication_state']) {
    'scheduled' => 'Scheduled publication',
    'published' => 'Publication date',
    default => 'Modify time',
};
?>
<span class="blog-list-date-label"><?= htmlspecialchars($trans($dateLabel), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($timestamp > 0): ?>
    <time data-local-time="datetime" datetime="<?= gmdate(DATE_ATOM, $timestamp) ?>"><?= gmdate('Y-m-d H:i', $timestamp) ?></time>
<?php else: ?>
    <span>—</span>
<?php endif; ?>
