<?php

declare(strict_types=1);

/** @var array<string, mixed> $row */
/** @var callable $trans */

$roleFields = [
    'edit_users',
    'edit_site',
    'create_articles',
    'edit_comments',
    'hide_comments',
    'view_hidden',
    'view',
];

foreach ($roleFields as $field) {
    if ((bool)($row['column_' . $field] ?? false)) {
        echo '<span class="user-role">' . htmlspecialchars($trans($field), ENT_QUOTES, 'UTF-8') . '</span>';

        return;
    }
}

echo '<span class="user-role user-role-none">' . htmlspecialchars($trans('No admin access'), ENT_QUOTES, 'UTF-8') . '</span>';
