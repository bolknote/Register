<?php
/**
 * Front controller for the admin panel.
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

use Register\Http\ContentSecurityPolicy;
use S2\Cms\Admin\AdminRequestHandler;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Queue\ShutdownWorkCoordinator;
use Symfony\Component\HttpFoundation\Request;

// NOTE: find a more elegant way to boot the application with the AdminExtension
const S2_ADMIN_MODE = true;

$app = require __DIR__ . '/../_include/common.php';

$request = Request::createFromGlobals();
$handler  = $app->container->get(AdminRequestHandler::class);
$response = $handler->handle($request);
$reportUri = $app->container->get(UrlBuilder::class)->rawLink(ContentSecurityPolicy::REPORT_PATH);
ContentSecurityPolicy::applyToAdmin($response, $reportUri);

header_remove('X-Powered-By');
$shutdownCoordinator = $app->container->get(ShutdownWorkCoordinator::class);
$shutdownCoordinator->closeSession();
$response->send(false);
$shutdownCoordinator->finishResponse();
