<?php

declare(strict_types = 1);

/**
 * Processing all public pages of the site.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

use Register\Http\ContentSecurityPolicy;
use Register\Http\ResponseCompressionCache;
use Register\Http\ResponseCompressor;
use Register\Http\TrustedScriptNonceInjector;
use Register\Offline\OfflineCachePolicy;
use Register\Core\Model\AuthProvider;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Register\Core\Security\Monitoring\SecurityTelemetryRecorder;
use Symfony\Component\HttpFoundation\Request;

$app = require __DIR__ . '/_include/common.php';
$shutdownCoordinator = $app->container->get(ShutdownWorkCoordinator::class);

header_remove('X-Powered-By');

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
    $shutdownCoordinator->finishResponse();
    exit;
}

$request  = Request::createFromGlobals();
$response  = $app->handle($request);
$app->container->get(SecurityTelemetryRecorder::class)->recordResponse($request, $response);
$reportUri        = $basePath . $urlPrefix . ContentSecurityPolicy::REPORT_PATH;
$scriptNonce      = ContentSecurityPolicy::generateScriptNonce();
$trustedInlineElementNum = (new TrustedScriptNonceInjector())->injectIntoResponse($response, $scriptNonce);
ContentSecurityPolicy::apply($response, $reportUri, $trustedInlineElementNum > 0 ? $scriptNonce : null);

if ($trustedInlineElementNum > 0) {
    // A fresh nonce changes the body on every request. Reusing a cached body with a new
    // CSP header after a 304 response would block its scripts, so these rare pages must be 200s.
    $response->headers->remove('ETag');
    $response->headers->remove('Last-Modified');
}

// Disable cache since all the pages are generated dynamically. We only use conditional GET.
$response->headers->set('Pragma', 'no-cache');
$response->setExpires(new DateTimeImmutable('-1 day'));
$response->isNotModified($request);

$response->prepare($request);
OfflineCachePolicy::apply(
    $request,
    $response,
    $app->container->get(AuthProvider::class)->hasAuthenticatedPublicSession($request),
);
$shutdownCoordinator->closeSession();

// Authentication actions are directly user-facing and do not enqueue work. On SAPIs without
// response detachment (notably PHP's development server), attached queue processing otherwise
// makes a simple sign-in or sign-out appear to hang after it has already succeeded.
if (
    $request->attributes->has('auth_action')
    || (
        $request->headers->get('X-Register-Foreground-Only') === '1'
        && $request->headers->get('X-Register-Navigation') === 'partial'
    )
) {
    $shutdownCoordinator->suppressBackgroundWork();
}

if ($response->isInformational() || $response->isEmpty() || $response->getContent() === false || $response->getContent() === '') {
    $response->send(false);
} else {
    $compressor = ResponseCompressor::fromEnvironment(
        $app->container->get(ResponseCompressionCache::class),
    );
    $compressor->compress($request, $response);

    if ($compressor->canSetContentLength()) {
        $response->headers->set('Content-Length', (string)\strlen((string)$response->getContent()));
    } else {
        $response->headers->remove('Content-Length');
    }

    $response->send(false);
}

$shutdownCoordinator->finishResponse();
