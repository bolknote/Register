<?php

declare(strict_types = 1);

$requestedSeconds = $_GET['time'] ?? null;
$seconds = \is_string($requestedSeconds) ? filter_var($requestedSeconds, FILTER_VALIDATE_INT) : 1;
if (!\is_int($seconds) || $seconds < 0 || $seconds > 10) {
    http_response_code(400);
    exit('Invalid sleep duration.');
}

sleep($seconds);

echo 'Slept for ' . $seconds . ' seconds.';
