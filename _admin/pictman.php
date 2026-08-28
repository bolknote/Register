<?php

declare(strict_types = 1);

/**
 * Picture manager
 *
 * Maintain picture displaying and management
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

use Register\Core\Http\ContentSecurityPolicy;
use Register\AdminYard\TemplateRenderer;
use Register\Core\Model\AuthManager;
use Register\Core\Model\UrlBuilder;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

define('REGISTER_ADMIN_MODE', true);
$app = require __DIR__ . '/../_include/common.php';

$request = Request::createFromGlobals();

$authManager = $app->container->get(AuthManager::class);
$response    = $authManager->checkAuthenticatedUser($request);
if ($response === null) {
    $templateRenderer = $app->container->get(TemplateRenderer::class);
    $content          = $templateRenderer->render('_admin/templates/picture-manager.php.inc', [
        'imagePath' => $app->container->getParameter('image_path'),
    ]);
    $response         = new Response($content);
}

$reportUri = $app->container->get(UrlBuilder::class)->rawLink(ContentSecurityPolicy::REPORT_PATH);
ContentSecurityPolicy::applyToEmbeddedAdmin($response, $reportUri);

header_remove('X-Powered-By');
$shutdownCoordinator = $app->container->get(ShutdownWorkCoordinator::class);
$shutdownCoordinator->closeSession();
$response->send(false);
$shutdownCoordinator->finishResponse();
