<?php

declare(strict_types = 1);

/**
 * Picture manager
 *
 * Maintain picture displaying and management
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

use Register\Http\ContentSecurityPolicy;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Model\AuthManager;
use S2\Cms\Queue\ShutdownWorkCoordinator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

define('S2_ADMIN_MODE', true);
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

ContentSecurityPolicy::applyToAdmin($response);

header_remove('X-Powered-By');
$shutdownCoordinator = $app->container->get(ShutdownWorkCoordinator::class);
$shutdownCoordinator->closeSession();
$response->send(false);
$shutdownCoordinator->finishResponse();
