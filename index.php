<?php

declare(strict_types = 1);

/**
 * Processing all public pages of the site.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

use S2\Cms\Config\DynamicConfigProvider;
use Symfony\Component\HttpFoundation\Request;

$app = require __DIR__ . '/_include/common.php';

header('X-Powered-By: S2/' . S2_VERSION);

$urlPrefix = $app->container->getStringParameter('url_prefix');
$basePath  = $app->container->getParameter('base_path');
$basePath  = $basePath === null ? '' : (string)$basePath;

// We create our own request URI with the path removed and only the parts to rewrite included
if (isset($_SERVER['PATH_INFO']) && $urlPrefix !== '') {
    $request_uri = $_SERVER['PATH_INFO'];
} else {
    $request_uri = substr($_SERVER['REQUEST_URI'], strlen($urlPrefix));
    if (!str_starts_with($request_uri, '/')) {
        // Fix for usual URLS (e.g. '/?search=1&q=text' in case of prefix === '/?')
        $request_uri = '/';
    } elseif (($delimiter = strpos($request_uri, $urlPrefix !== '' ? '&' : '?')) !== false) {
        $request_uri = substr($request_uri, 0, $delimiter);
    }

    // Hack for symfony router in case of /? and /index.php? prefix.
    $_SERVER['REQUEST_URI'] = $request_uri;
}

//
// Redirect to the admin page
//
if (str_ends_with($request_uri, '---')) {
    header('Location: ' . $basePath . '/_admin/index.php?path=' . urlencode(substr($request_uri, 0, -3)));
    die;
}

$request  = Request::createFromGlobals();
$response = $app->handle($request);

// Disable cache since all the pages are generated dynamically. We only use conditional GET.
$response->headers->set('Pragma', 'no-cache');
$response->setExpires(new DateTimeImmutable('-1 day'));
$response->isNotModified($request);

$response->prepare($request);

if ($response->isInformational() || $response->isEmpty() || $response->getContent() === false || $response->getContent() === '') {
    $response->send(false);
} else {
    // Custom response sending to set Content-Length properly and to enable compression
    ob_start();

    $useCompression = $app->container->get(DynamicConfigProvider::class)->getBoolProxy('S2_COMPRESS')->get();
    if ($useCompression === true) {
        ob_start('ob_gzhandler');
    }

    $response->sendContent();

    if ($useCompression === true) {
        ob_end_flush();
    }

    $response->headers->set('Content-Length', (string)ob_get_length());
    $response->sendHeaders();

    ob_end_flush();
}

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    if (\extension_loaded('newrelic')) {
        newrelic_end_transaction();
        $newRelicAppName = ini_get('newrelic.appname');
        newrelic_start_transaction(is_string($newRelicAppName) ? $newRelicAppName : 'S2');
        newrelic_name_transaction('index_background');
    }

    $consumer  = $app->container->get(\S2\Cms\Queue\QueueConsumer::class);
    $startedAt = microtime(true);
    while (microtime(true) - $startedAt < 10) {
        if (!$consumer->runQueue()) {
            break;
        }
    }
}
