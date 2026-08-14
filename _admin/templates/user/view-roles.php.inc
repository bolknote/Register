<?php

declare(strict_types=1);

/** @var array<string, mixed> $row */
/** @var callable $trans */

$roleFields = [
    'view',
    'view_hidden',
    'hide_comments',
    'edit_comments',
    'create_articles',
    'edit_site',
    'edit_users',
];

$roles = [];
foreach ($roleFields as $field) {
    if ((bool)($row['column_' . $field] ?? false)) {
        $roles[] = '<span class="user-role">' . htmlspecialchars($trans($field), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

echo $roles === [] ? '<span class="null">null</span>' : implode(' ', $roles);
