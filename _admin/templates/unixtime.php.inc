<?php

declare(strict_types=1);

if (!isset($this)) {
    die;
}

/** @var mixed $value */

if ($value === null || $value === '') {
    echo '<span class="null">null</span>';
} else {
    echo htmlspecialchars(date('Y-m-d H:i:s', (int)$value), ENT_QUOTES, 'UTF-8');
}
