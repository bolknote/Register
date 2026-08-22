<?php
/**
 * Front controller for custom ajax requests in the admin panel.
 *
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Http\ContentSecurityPolicy;
use Register\Core\Admin\AdminAjaxRequestHandler;
use Register\Core\Model\UrlBuilder;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Register\Core\Security\Monitoring\SecurityTelemetryRecorder;
use Symfony\Component\HttpFoundation\Request;

// NOTE: find a more elegant way to boot the application with the AdminExtension
const REGISTER_ADMIN_MODE = true;

$app = require __DIR__ . '/../_include/common.php';

$request = Request::createFromGlobals();
$handler  = $app->container->get(AdminAjaxRequestHandler::class);
$response = $handler->handle($request);
$action = $request->query->getString('action', $request->request->getString('action'));
$app->container->get(SecurityTelemetryRecorder::class)->recordResponse(
    $request,
    $response,
    $action === 'upload',
);
$reportUri = $app->container->get(UrlBuilder::class)->rawLink(ContentSecurityPolicy::REPORT_PATH);
ContentSecurityPolicy::applyToAdmin($response, $reportUri);

header_remove('X-Powered-By');
$shutdownCoordinator = $app->container->get(ShutdownWorkCoordinator::class);
$shutdownCoordinator->closeSession();
$response->send(false);
$shutdownCoordinator->finishResponse();
