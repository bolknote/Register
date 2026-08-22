<?php

declare(strict_types = 1);

$roseTestProcessId = getmypid();
if ($roseTestProcessId === false) {
    $roseTestProcessId = random_int(1, PHP_INT_MAX);
}

$roseTestDatabase = sys_get_temp_dir() . '/register-rose-' . $roseTestProcessId . '.sqlite';

$GLOBALS['register_rose_test_db'] = [
    'dsn'      => 'sqlite:' . $roseTestDatabase,
    'username' => '',
    'passwd'   => '',
];

register_shutdown_function(static function () use ($roseTestDatabase): void {
    if (is_file($roseTestDatabase)) {
        unlink($roseTestDatabase);
    }
});
