<?php

declare(strict_types = 1);

use Register\Core\Http\DevelopmentRouterPolicy;

if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('The development router must run under the PHP built-in server.');
}

$rootDir     = dirname(__DIR__);
$policyFile  = $rootDir . '/_include/src/Http/DevelopmentRouterPolicy.php';
require_once $policyFile;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$filePath    = realpath($rootDir . $requestPath);

if (
    $filePath !== false
    && is_dir($filePath)
    && str_starts_with($requestPath, '/files/')
    && is_file($filePath . '/index.html')
) {
    $filePath    .= '/index.html';
    $requestPath  = rtrim($requestPath, '/') . '/index.html';
}

if ($filePath !== false && is_file($filePath) && str_starts_with($filePath, $rootDir . DIRECTORY_SEPARATOR)) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($extension === 'php') {
        if (DevelopmentRouterPolicy::isAllowedPhpEndpoint($requestPath)) {
            return false;
        }

        http_response_code(404);
        return true;
    }

    if (DevelopmentRouterPolicy::isAllowedStaticFile($requestPath, $extension)) {
        return false;
    }

    http_response_code(404);
    return true;
}

require $rootDir . '/index.php';

return true;
