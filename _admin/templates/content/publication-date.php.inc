<?php

declare(strict_types = 1);

/** @var string|int|null $value */

$timestamp = (int)$value;
?>
<?php if ($timestamp > 0): ?>
    <time data-local-time="datetime" datetime="<?= gmdate(DATE_ATOM, $timestamp) ?>"><?= gmdate('Y-m-d H:i', $timestamp) ?></time>
<?php else: ?>
    <span>—</span>
<?php endif; ?>
