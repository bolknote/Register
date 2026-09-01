<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var array<string, mixed> $row */
/** @var callable $trans */

$name = trim((string)$value);
if ($name !== '') {
    echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    return;
}

$login = trim((string)($row['column_login'] ?? ''));
$placeholder = str_starts_with($login, 'external_import_')
    ? $trans('Unnamed imported commenter')
    : $trans('Unnamed guest');

?><span class="user-name-placeholder"><?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?></span>
