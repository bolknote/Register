<?php

if ($row['virtual_current'] === '1') {
    echo $trans('Current session');
} else {
    require __DIR__ . '/../admin-yard/list-actions.php.inc';
}
