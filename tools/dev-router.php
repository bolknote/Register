<?php

declare(strict_types = 1);

if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('The development router must run under the PHP built-in server.');
}

$rootDir     = dirname(__DIR__);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$filePath    = realpath($rootDir . $requestPath);

if ($filePath !== false && is_file($filePath) && str_starts_with($filePath, $rootDir . DIRECTORY_SEPARATOR)) {
    $phpEndpoints = [
        '/index.php',
        '/_admin/ajax.php',
        '/_admin/index.php',
        '/_admin/install.php',
        '/_admin/pictman.php',
        '/_extensions/s2_counter/counter.php',
        '/_extensions/s2_counter/data.php',
    ];

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($extension === 'php') {
        if (in_array($requestPath, $phpEndpoints, true)) {
            return false;
        }

        http_response_code(404);
        return true;
    }

    $publicPrefixes = [
        '/_admin/',
        '/_cache/',
        '/_extensions/',
        '/_pictures/',
        '/_styles/',
    ];
    $publicExtensions = [
        'avif', 'bmp', 'css', 'gif', 'html', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map',
        'mp3', 'mp4', 'ogg', 'png', 'svg', 'wasm', 'wav', 'webm', 'webp', 'woff', 'woff2',
    ];

    foreach ($publicPrefixes as $prefix) {
        if (str_starts_with($requestPath, $prefix) && in_array($extension, $publicExtensions, true)) {
            return false;
        }
    }

    http_response_code(404);
    return true;
}

require $rootDir . '/index.php';

return true;
