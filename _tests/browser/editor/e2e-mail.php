<?php

declare(strict_types = 1);

// Disposable sendmail replacement: capture the real MIME email, never deliver it.
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    throw new RuntimeException('Pass the disposable mail log path.');
}

$message = stream_get_contents(STDIN);
if ($message === false || file_put_contents($argv[1], json_encode($message, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
    throw new RuntimeException('Unable to capture the fixture email.');
}
