<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Psr\Log\LoggerInterface;
use Register\Url\ContentUrlGenerator;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Helper\StringHelper;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Public sign-in, sign-out, provider callback and unread-comment routes. */
final readonly class PublicAuthController implements ControllerInterface
{
    public function __construct(
        private AuthProvider                  $authProvider,
        private PublicSessionManager          $sessionManager,
        private PublicAuthRepository          $repository,
        private PublicOAuthClient             $oauthClient,
        private MagicLinkService              $magicLinkService,
        private PublicAuthRenderer            $renderer,
        private PublicAuthFormToken           $formToken,
        private CommentNotificationRepository $notifications,
        private ContentUrlGenerator           $contentUrlGenerator,
        private HtmlTemplateProvider          $templateProvider,
        private UrlBuilder                    $urlBuilder,
        private TranslatorInterface           $translator,
        private LoggerInterface               $logger,
        private VisitorIdentityManager         $visitorIdentityManager,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $action = $request->attributes->getString('auth_action', 'page');

        try {
            return match ($action) {
                'page'           => $this->page($request),
                'password'       => $this->password($request),
                'logout'         => $this->logout($request),
                'email'          => $this->requestEmail($request),
                'email_callback' => $this->emailCallback($request),
                'oauth_start'    => $this->oauthStart($request),
                'oauth_callback' => $this->oauthCallback($request),
                'unread'         => $this->unread($request),
                'check_email'    => $this->messagePage(
                    $this->translator->trans('Check your email'),
                    '<p>' . register_htmlencode($this->translator->trans('We sent a one-time sign-in link. It is valid for 15 minutes.')) . '</p>',
                ),
                default => new Response('Not found.', Response::HTTP_NOT_FOUND),
            };
        } catch (MagicLinkRateLimitException $exception) {
            $response = $this->error(
                $request,
                $this->translator->trans('Too many sign-in links. Try again later.'),
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', (string)$exception->retryAfter);

            return $response;
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $throwable) {
            $this->logger->error('Public authentication request failed.', [
                'action'    => $action,
                'exception' => $throwable,
            ]);

            return $this->error(
                $request,
                $this->translator->trans('Unable to sign in. Please try again.'),
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    private function page(Request $request): Response
    {
        return $this->messagePage(
            $this->translator->trans('Sign in'),
            $this->renderer->renderPanel($request),
        );
    }

    private function password(Request $request): Response
    {
        $this->requirePostToken($request);
        $returnPath = PublicReturnPath::normalize($request->request->getString('return_path'));
        $response = $this->sessionManager->loginWithPassword($request);
        if (!$response->isSuccessful()) {
            if ($this->wantsJson($request)) {
                return $response;
            }

            return $this->error($request, $this->translator->trans('Incorrect login or password'), $response->getStatusCode());
        }

        $login = trim($request->request->getString('login'));
        $userId = $this->repository->findUserIdByLogin($login);
        if ($userId !== null) {
            $this->repository->ensureNotificationBaseline($userId);
            $this->visitorIdentityManager->recordInteraction($request, $userId);
        }

        return $this->success($request, $response, $returnPath);
    }

    private function logout(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return new Response('Logout requires POST.', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }
        if (!$this->sessionManager->logoutCsrfTokenMatches($request, $request->request->getString('csrf_token'))) {
            return $this->error($request, $this->translator->trans('The session has changed. Reload the page.'), Response::HTTP_FORBIDDEN);
        }

        $returnPath = PublicReturnPath::normalize($request->request->getString('return_path'));
        return $this->success($request, $this->sessionManager->logout($request), $returnPath);
    }

    private function requestEmail(Request $request): Response
    {
        $this->requirePostToken($request);
        $email = mb_strtolower(trim($request->request->getString('email')));
        if (!StringHelper::isValidEmail($email)) {
            throw new \InvalidArgumentException($this->translator->trans('Enter a valid email address'));
        }
        $name = mb_substr(trim($request->request->getString('name')), 0, 80);
        $returnPath = PublicReturnPath::normalize($request->request->getString('return_path'));
        $this->magicLinkService->requestLogin($request, $email, $name, $returnPath);
        $checkEmailUrl = $this->urlBuilder->rawLink('/auth/check-email');

        if ($this->wantsJson($request)) {
            return $this->json(['success' => true, 'redirect' => $checkEmailUrl]);
        }

        return new RedirectResponse($checkEmailUrl);
    }

    private function emailCallback(Request $request): Response
    {
        $result = $this->magicLinkService->consume($request->query->getString('token'));
        $returnPath = $result['return_path'];
        if ($result['comment_id'] !== null && $result['published']) {
            $returnPath = (preg_replace('/#.*$/D', '', $returnPath) ?? $returnPath)
                . '#comment-' . $result['comment_id'];
        }

        $session = $this->sessionManager->loginVerifiedUser(
            $request,
            $result['user_id'],
            true,
            SecurityAuditLogger::AUTH_EMAIL_MAGIC,
        );
        $this->visitorIdentityManager->recordInteraction($request, $result['user_id']);

        return $this->redirectWithCookies($session, $returnPath);
    }

    private function oauthStart(Request $request): Response
    {
        $provider = $request->attributes->getString('provider');
        $returnPath = PublicReturnPath::normalize($request->query->getString('return'));

        return new RedirectResponse($this->oauthClient->authorizationUrl($provider, $returnPath));
    }

    private function oauthCallback(Request $request): Response
    {
        $provider = $request->attributes->getString('provider');
        $identity = $this->oauthClient->exchange($request, $provider);
        $userId = $this->repository->findOrCreateIdentity(
            $identity->provider,
            $identity->subject,
            $identity->email,
            $identity->displayName,
            $identity->avatarUrl,
        );
        $auditMethod = match ($identity->provider) {
            'vk'      => SecurityAuditLogger::AUTH_VK,
            'mail_ru' => SecurityAuditLogger::AUTH_MAIL_RU,
            'ok_ru'   => SecurityAuditLogger::AUTH_OK_RU,
            default   => SecurityAuditLogger::AUTH_YANDEX,
        };
        $session = $this->sessionManager->loginVerifiedUser($request, $userId, true, $auditMethod);
        $this->visitorIdentityManager->recordInteraction($request, $userId);

        return $this->redirectWithCookies($session, $identity->returnPath);
    }

    private function unread(Request $request): Response
    {
        $user = $this->authProvider->getAuthenticatedPublicUser($request);
        if (!$user instanceof \Register\Core\Model\AuthenticatedPublicUser) {
            return new RedirectResponse($this->urlBuilder->rawLink('/auth', [
                'return=' . rawurlencode($request->getRequestUri()),
            ]));
        }
        $notification = $this->notifications->firstUnread($user);
        if (!$notification instanceof CommentNotification) {
            return new RedirectResponse($this->urlBuilder->rawLink('/'));
        }

        $contentPath = $this->contentUrlGenerator->path($notification->contentId, true);
        if ($contentPath === null) {
            return new RedirectResponse($this->urlBuilder->rawLink('/'));
        }

        return new RedirectResponse(html_entity_decode($contentPath) . '#comment-' . $notification->commentId);
    }

    private function requirePostToken(Request $request): void
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            throw new \InvalidArgumentException('This action requires POST.');
        }
        if (!$this->formToken->matches($request->request->getString('auth_token'))) {
            throw new \InvalidArgumentException($this->translator->trans('The form has expired. Reload the page.'));
        }
    }

    private function success(Request $request, JsonResponse $session, string $returnPath): Response
    {
        if ($this->wantsJson($request)) {
            $payload = ['success' => true, 'redirect' => $returnPath];
            $response = $this->json($payload);
            $this->copyCookies($session, $response);

            return $response;
        }

        return $this->redirectWithCookies($session, $returnPath);
    }

    private function redirectWithCookies(JsonResponse $session, string $returnPath): RedirectResponse
    {
        $response = new RedirectResponse(PublicReturnPath::normalize($returnPath));
        $this->copyCookies($session, $response);

        return $response;
    }

    private function copyCookies(Response $source, Response $target): void
    {
        foreach ($source->headers->getCookies(ResponseHeaderBag::COOKIES_FLAT) as $cookie) {
            $target->headers->setCookie($cookie);
        }
    }

    private function messagePage(string $title, string $html): Response
    {
        $template = $this->templateProvider->getTemplate('service.php');
        $template
            ->putInPlaceholder('head_title', $title)
            ->putInPlaceholder('title', $title)
            ->putInPlaceholder('text', $html)
        ;

        return $template->toHttpResponse();
    }

    private function error(Request $request, string $message, int $status): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(['success' => false, 'message' => $message], $status);
        }

        $response = $this->messagePage(
            $this->translator->trans('Unable to sign in'),
            '<p>' . register_htmlencode($message) . '</p>',
        );
        $response->setStatusCode($status);

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string)$request->headers->get('Accept'), 'application/json');
    }
}
