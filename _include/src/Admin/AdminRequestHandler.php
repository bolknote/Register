<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

use Register\Content\ContentChangeDispatcher;
use Register\Http\ContentSecurityPolicy;
use Register\Core\Admin\Event\RedirectFromPublicEvent;
use Register\Core\Admin\WebAuthn\WebAuthnAdminController;
use Register\Core\Framework\Container;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\AuthManager;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\SameOriginRequestGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Register\Core\Pdo\DbLayerException;

readonly class AdminRequestHandler
{
    public function __construct(
        private RequestStack             $requestStack,
        private AuthManager              $authManager,
        private AdminThemeStylesheet     $adminThemeStylesheet,
        private WebAuthnAdminController  $webAuthnController,
        private SameOriginRequestGuard   $sameOriginRequestGuard,
        private EventDispatcherInterface $eventDispatcher,
        private Container                $container,
        private ContentChangeDispatcher  $contentChangeDispatcher,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function handle(Request $request): Response
    {
        foreach ($this->container->getByTagIfInstantiated(StatefulServiceInterface::class) as $service) {
            $service->clearState();
        }

        $request->setSession(new Session());
        $this->requestStack->push($request);

        try {
            $originViolation = $this->sameOriginRequestGuard->violation($request);
            if ($originViolation !== null) {
                $response = new Response(
                    $this->container->get(\Register\AdminYard\Translator::class)->trans($originViolation),
                    Response::HTTP_FORBIDDEN,
                );
                ContentSecurityPolicy::applyToAdmin($response);

                return $response;
            }

            if ($this->adminThemeStylesheet->supports($request)) {
                $response = $this->adminThemeStylesheet->handle($request);
            } elseif ($this->webAuthnController->isPublicAction($request)) {
                $response = $this->webAuthnController->handlePublic($request);
            } else {
                $response = $this->authManager->checkAuth($request);
                if (!$response instanceof Response && $this->webAuthnController->isAuthenticatedAction($request)) {
                    $response = $this->webAuthnController->handleAuthenticated($request);
                }

                if (!$response instanceof Response) {
                    if (!$request->query->has('entity')
                        && !$request->query->has('path')
                        && $request->query->getString('action') === ''
                        && $this->container->get(PermissionChecker::class)->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
                        $request->query->set('entity', 'Dashboard');
                    }

                    if ($request->query->has('path') && !$request->query->has('entity')) {
                        // Redirect from public pages to the admin panel.
                        // Listeners must modify the request if they recognize the path.
                        $this->eventDispatcher->dispatch(new RedirectFromPublicEvent($request, $request->query->getString('path')));
                    }

                    // NOTE: Initialization of the AdminPanel is delayed since its factory is relied on the RequestStack to be populated
                    $adminPanelFactory = $this->container->get(AdminPanelFactory::class);
                    $adminPanel        = $adminPanelFactory->create();
                    $response          = $adminPanel->handleRequest($request);
                }
            }

            $this->contentChangeDispatcher->flush();
            $this->authManager->renewPersistentCookies($request, $response);
            ContentSecurityPolicy::applyToAdmin($response);

            return $response;
        } finally {
            $this->requestStack->pop();
        }
    }
}
