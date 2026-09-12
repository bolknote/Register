<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var string $label */
/** @var array<string, scalar>|null $linkParams */
/** @var array<string, mixed> $row */

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
?>
<?php if ($linkParams !== null): ?>
    <a href="?<?= $html(http_build_query($linkParams)) ?>"><?= $html($label) ?></a>
<?php else: ?>
    <span><?= $value === null ? '0' : $html($value) ?></span>
<?php endif; ?>
<?php if ($row['column_comments_enabled'] === false): ?>
    <i class="icon icon-comments-disabled"></i>
<?php endif; ?>
