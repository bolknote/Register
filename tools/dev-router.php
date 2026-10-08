<?php

declare(strict_types = 1);

use Register\Core\Http\DevelopmentRouterPolicy;

if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('The development router must run under the PHP built-in server.');
}

$rootDir     = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
if ($rootDir === false) {
    throw new RuntimeException('The development document root does not exist.');
}

$policyFile  = dirname(__DIR__) . '/_include/src/Http/DevelopmentRouterPolicy.php';
require_once $policyFile;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$negotiatedAssetPath = DevelopmentRouterPolicy::negotiatedAssetPath($requestPath);
if ($negotiatedAssetPath !== null) {
    $negotiatedFilePath = realpath($rootDir . $negotiatedAssetPath);
    if ($negotiatedFilePath === false) {
        http_response_code(404);
        return true;
    }

    if (!is_file($negotiatedFilePath)
        || !str_starts_with($negotiatedFilePath, $rootDir . DIRECTORY_SEPARATOR)
    ) {
        http_response_code(404);
        return true;
    }

    serveDevelopmentAsset($negotiatedFilePath, $requestPath);

    return true;
}

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
        if (in_array($extension, ['css', 'js', 'mjs'], true)) {
            serveDevelopmentAsset($filePath, $requestPath);

            return true;
        }

        return false;
    }

    http_response_code(404);
    return true;
}

require $rootDir . '/index.php';

return true;

/** Serve the same prepared asset representations as Apache, without running an encoder. */
function serveDevelopmentAsset(string $filename, string $requestPath): void
{
    require_once dirname(__DIR__) . '/_vendor/autoload.php';
    $accepted = \Symfony\Component\HttpFoundation\AcceptHeader::fromString($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    $choices = [];
    foreach (['br' => '.br', 'zstd' => '.zst', 'gzip' => '.gz'] as $encoding => $suffix) {
        $quality = $accepted->get($encoding)?->getQuality() ?? 0.0;
        if ($quality > 0.0 && is_file($filename . $suffix)) {
            $choices[$encoding] = $quality;
        }
    }

    arsort($choices, SORT_NUMERIC);
    $encoding = array_key_first($choices);
    $suffix = match ($encoding) {
        'br' => '.br',
        'zstd' => '.zst',
        'gzip' => '.gz',
        default => '',
    };
    $representationPath = realpath($filename . $suffix);
    // A sidecar must not resolve to a different, potentially private file.
    if ($representationPath === false || is_link($representationPath)
        || $representationPath !== $filename . $suffix
    ) {
        http_response_code(404);
        return;
    }

    $content = file_get_contents($representationPath);
    if (!\is_string($content)) {
        http_response_code(500);
        return;
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($extension === 'css' ? 'text/css' : 'application/javascript') . '; charset=UTF-8');
    $immutable = !str_starts_with($requestPath, '/service-worker.js') && (
        str_starts_with($requestPath, '/_cache/')
        || (str_ends_with($requestPath, '.asset')
            && preg_match('/(?:^|&)v=[a-f0-9]{64}(?:&|$)/D', $_SERVER['QUERY_STRING'] ?? '') === 1)
    );
    header('Cache-Control: ' . ($immutable ? 'public, max-age=31536000, immutable' : 'no-cache'));
    header('Vary: Accept-Encoding');
    $etag = '"' . hash('sha256', $content) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }

    if ($encoding !== null) {
        header('Content-Encoding: ' . $encoding);
    }

    header('Content-Length: ' . \strlen($content));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $content;
    }
}
