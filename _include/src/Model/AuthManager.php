<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Register\Core\Pdo\DbLayerException;

readonly class AuthManager
{
    public const string FORCE_AJAX_RESPONSE = '_force_ajax_response';

    public const int PERSISTENT_SESSION_LIFETIME = 30 * 86400;

    private const int PERSISTENT_SESSION_IDLE_TIMEOUT = 7 * 86400;

    private const int TEMPORARY_SESSION_IDLE_TIMEOUT = 30 * 60;

    private const int TEMPORARY_SESSION_LIFETIME = 12 * 3600;

    private const string SESSION_TOKEN_PATTERN = '/^([pt])([0-9a-f]{8})[0-9a-f]{64}$/D';

    private const string SESSION_STATUS_LOST     = 'Lost';

    private const string SESSION_STATUS_OK       = 'Ok';

    private const string SESSION_STATUS_FORBIDDEN = 'Forbidden';

    public function __construct(
        private DbLayer           $dbLayer,
        private PermissionChecker $permissionChecker,
        private RequestStack      $requestStack,
        private TemplateRenderer  $templateRenderer,
        private Translator        $translator,
        private LoginRateLimiter  $loginRateLimiter,
        private SecurityAuditLogger $securityAuditLogger,
        private string            $basePath,
        private string            $baseUrl,
        private string            $cookieName,
        private bool              $forceAdminHttps,
    ) {
    }

    /**
     * Checks credentials, processes login form, handles logout and returns unauthorized response if needed
     * (JSON for AJAX or login form for non-AJAX).
     *
     * Supposed to be used for admin main page and AJAX page controllers.
     *
     * @throws DbLayerException
     */
    public function checkAuth(Request $request): ?Response
    {
        if ($this->forceAdminHttps && !$request->isSecure()) {
            return new RedirectResponse($this->configuredOrigin('https') . $request->getRequestUri());
        }

        $this->cleanupExpiredSessions();

        $sessionId = $request->cookies->get($this->cookieName, '');

        if ($request->query->get('action') === 'logout') {
            if (!$request->isMethod(Request::METHOD_POST)) {
                return new Response('Logout requires POST.', Response::HTTP_METHOD_NOT_ALLOWED, [
                    'Allow' => Request::METHOD_POST,
                ]);
            }

            if (!$this->logoutCsrfTokenMatches($sessionId, $request->request->getString('csrf_token'))) {
                return new Response('Invalid logout token.', Response::HTTP_FORBIDDEN);
            }

            $this->deleteSession($sessionId);
            $baseUrl = $this->shouldUseSecureCookies($request)
                ? (preg_replace('/^http:/i', 'https:', $this->baseUrl) ?? $this->baseUrl)
                : $this->baseUrl;
            $response      = new RedirectResponse(rtrim($baseUrl, '/') . '/_admin/index.php');
            $secureCookies = $this->shouldUseSecureCookies($request);
            $response->headers->setCookie($this->createAdminCookie('', $secureCookies));
            $response->headers->setCookie($this->createCommentCookie('', $secureCookies, 0));
            return $response;
        }

        if ($sessionId === '') {
            if ($request->query->get('action') === 'login') {
                return $this->processLoginForm($request);
            }

            // New session
            return $this->createUnauthorizedResponse($request);
        }

        // Existed session
        return $this->authenticateUser($request, $sessionId);
    }

    /**
     * Checks credentials and returns unauthorized response if required.
     *
     * Supposed to be used in the admin front page controllers not covered by AdminYard pages.
     *
     * @throws DbLayerException
     */
    public function checkAuthenticatedUser(Request $request): ?Response
    {
        if ($this->forceAdminHttps && !$request->isSecure()) {
            return new RedirectResponse($this->configuredOrigin('https') . $request->getRequestUri());
        }

        $sessionId = $request->cookies->get($this->cookieName, '');
        if ($sessionId === '') {
            return $this->createAccessDeniedResponse($request);
        }

        $status = $this->checkAndUpdateCurrentUserSession($request, $sessionId);
        if ($status === self::SESSION_STATUS_FORBIDDEN) {
            return $this->createAudienceDeniedResponse($request);
        }

        if ($status !== self::SESSION_STATUS_OK || !$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return $this->createAccessDeniedResponse($request);
        }

        return null;
    }

    public function getCurrentSessionId(): string
    {
        // Authentication is evaluated for the request currently being handled.
        // A main request may still be present while an admin sub-request is
        // rendered, so using it here can leak a stale logout token into the
        // current user's menu.
        $request = $this->requestStack->getCurrentRequest();
        return $request instanceof \Symfony\Component\HttpFoundation\Request ? $request->cookies->get($this->cookieName, '') : '';
    }

    public function getCurrentSessionStorageKey(): string
    {
        $sessionId = $this->getCurrentSessionId();

        return $sessionId === '' ? '' : AuthTokenHasher::session($sessionId);
    }

    public function getLogoutCsrfToken(): string
    {
        return $this->getActionCsrfToken('logout');
    }

    public function getActionCsrfToken(string $purpose): string
    {
        $sessionId = $this->getCurrentSessionId();
        if ($sessionId === '' || preg_match('/^[a-z0-9_-]{1,64}$/D', $purpose) !== 1) {
            return '';
        }

        return $this->createActionCsrfToken($sessionId, $purpose);
    }

    public function actionCsrfTokenMatches(string $purpose, string $candidate): bool
    {
        $expected = $this->getActionCsrfToken($purpose);

        return AdminMutationGuard::tokensMatch($expected, $candidate);
    }

    /**
     * @throws DbLayerException
     */
    public function getTotalUserSessionsCount(): int
    {
        $result = $this->dbLayer
            ->select('COUNT(*)')
            ->from('users_online AS u1')
            ->innerJoin('users_online AS u2', 'u1.login = u2.login')
            ->where('u1.challenge = :challenge')
            ->setParameter('challenge', $this->getCurrentSessionStorageKey())
            ->andWhere('u1.audience = :current_audience')
            ->setParameter('current_audience', SessionAudience::ADMIN->value)
            ->andWhere('u2.audience = :peer_audience')
            ->setParameter('peer_audience', SessionAudience::ADMIN->value)
            ->execute()
        ;

        return (int)$result->result();
    }

    /** @throws DbLayerException */
    public function revokeUserSessions(int $userId, ?string $previousLogin = null): void
    {
        $currentLogin = $this->dbLayer
            ->select('login')
            ->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
            ->result()
        ;

        $logins = array_values(array_unique(array_filter(
            [$previousLogin, \is_string($currentLogin) ? $currentLogin : null],
            static fn(?string $login): bool => $login !== null && $login !== '',
        )));
        foreach ($logins as $login) {
            $this->dbLayer
                ->delete('users_online')
                ->where('login = :login')->setParameter('login', $login)
                ->execute()
            ;
        }
    }

    /** @throws DbLayerException */
    public function loginVerifiedUser(Request $request, int $userId, bool $remember, string $authMethod): JsonResponse
    {
        $result = $this->dbLayer
            ->select('login')
            ->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
        ;
        $login = $result->result();
        $result->freeResult();
        if (!\is_string($login) || $login === '') {
            $this->securityAuditLogger->authentication(
                $request,
                $authMethod,
                SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $this->createAjaxErrorLoginPasswordResponse();
        }

        $this->loginRateLimiter->clear($request->getClientIp() ?? '', $login);

        return $this->successLogin($request, $login, !$remember, $authMethod, $userId);
    }

    /** @throws DbLayerException */
    public function verifyCurrentPassword(Request $request, string $password): bool
    {
        $login = $this->permissionChecker->getUserLogin();
        if (!\is_string($login) || $login === '' || $password === '') {
            return false;
        }

        $clientIp = $request->getClientIp() ?? '';
        if ($this->loginRateLimiter->retryAfter($clientIp, $login) > 0) {
            return false;
        }

        $passwordHash = $this->getPasswordHash($login);
        if ($passwordHash === null || !password_verify($password, $passwordHash)) {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            return false;
        }

        if (PasswordHasher::needsRehash($passwordHash)) {
            $this->updatePasswordHash($login, $password);
        }

        $this->loginRateLimiter->clear($clientIp, $login);

        return true;
    }

    /** @throws DbLayerException */
    public function renewPersistentCookies(Request $request, Response $response): void
    {
        $sessionId = $request->cookies->get($this->cookieName, '');
        if ($sessionId === '' || str_starts_with($sessionId, 't')) {
            return;
        }

        $issuedAt      = $this->sessionIssuedAt($sessionId);
        $commentCookie = $request->cookies->get($this->cookieName . '_c', '');
        if ($issuedAt === null || $commentCookie === '' || time() >= $issuedAt + self::PERSISTENT_SESSION_LIFETIME) {
            return;
        }

        $sessionExists = $this->dbLayer
            ->select('COUNT(*)')
            ->from('users_online')
            ->where('challenge = :challenge')->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->andWhere('comment_cookie = :comment_cookie')->setParameter('comment_cookie', AuthTokenHasher::comment($commentCookie))
            ->andWhere('audience = :audience')->setParameter('audience', SessionAudience::ADMIN->value)
            ->execute()
            ->result()
        ;
        if ((int)$sessionExists !== 1) {
            return;
        }

        $secureCookies = $this->shouldUseSecureCookies($request);
        $expiresAt = $issuedAt + self::PERSISTENT_SESSION_LIFETIME;
        $response->headers->setCookie($this->createAdminCookie($sessionId, $secureCookies, $expiresAt));
        $response->headers->setCookie($this->createCommentCookie($commentCookie, $secureCookies, $expiresAt));
    }

    /**
     * @throws DbLayerException
     */
    private function cleanupExpiredSessions(): void
    {
        $this->dbLayer
            ->delete('users_online')
            ->where('time < :time')
            ->andWhere('login IS NOT NULL')
            ->setParameter('time', time() - self::PERSISTENT_SESSION_LIFETIME)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function authenticateUser(Request $request, string $sessionId): ?Response
    {
        $status = $this->checkAndUpdateCurrentUserSession($request, $sessionId);

        if ($status === self::SESSION_STATUS_OK) {
            if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
                return $this->createAccessDeniedResponse($request);
            }

            return null;
        }

        if ($status === self::SESSION_STATUS_FORBIDDEN) {
            return $this->createAudienceDeniedResponse($request);
        }

        // Some error detected
        $this->deleteSession($sessionId);

        if ($request->isXmlHttpRequest() || $request->attributes->get(self::FORCE_AJAX_RESPONSE) === true) {
            $response = new JsonResponse([
                'success' => false,
                'status'  => $status,
                'message' => $this->translator->trans($status . ' session ajax'),
            ], Response::HTTP_UNAUTHORIZED);
        } else {
            $response = $this->createLoginFormResponse($this->translator->trans($status . ' session'));
        }

        $secureCookies = $this->shouldUseSecureCookies($request);
        $response->headers->setCookie($this->createAdminCookie('', $secureCookies));
        $response->headers->setCookie($this->createCommentCookie('', $secureCookies, 0));

        return $response;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    private function getUserInfo(string $login): ?array
    {
        $result = $this->dbLayer
            ->select('*')
            ->from('users')
            ->where('login = :login')
            ->setParameter('login', $login)
            ->execute()
        ;

        $row = $result->fetchAssoc();
        return $row === false ? null : $row;
    }

    /**
     * @throws DbLayerException
     */
    private function touchSession(Request $request, string $sessionId): void
    {
        $this->dbLayer
            ->update('users_online')
            ->set('time', ':time')->setParameter('time', time())
            ->set('ua', ':ua')
            ->setParameter('ua', $request->headers->get('User-Agent'))
            ->set('ip', ':ip')
            ->setParameter('ip', $request->getClientIp())
            ->where('challenge = :challenge')
            ->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function processLoginForm(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return new Response('Login requires POST.', Response::HTTP_METHOD_NOT_ALLOWED, [
                'Allow' => Request::METHOD_POST,
            ]);
        }

        $login    = $request->request->getString('login');
        $password = $request->request->getString('pass');
        $clientIp = $request->getClientIp() ?? '';

        $retryAfter = $this->loginRateLimiter->retryAfter($clientIp, $login);
        if ($retryAfter > 0) {
            $this->securityAuditLogger->authentication(
                $request,
                SecurityAuditLogger::AUTH_PASSWORD,
                SecurityAuditLogger::OUTCOME_RATE_LIMITED,
                login: $login,
            );

            return $this->createRateLimitedLoginResponse($retryAfter);
        }

        if ($login === '') {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            $this->securityAuditLogger->authentication(
                $request,
                SecurityAuditLogger::AUTH_PASSWORD,
                SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $this->createAjaxErrorLoginPasswordResponse();
        }

        if ($password === '') {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            $this->securityAuditLogger->authentication(
                $request,
                SecurityAuditLogger::AUTH_PASSWORD,
                SecurityAuditLogger::OUTCOME_FAILURE,
                login: $login,
            );

            return $this->createAjaxErrorLoginPasswordResponse();
        }

        // Getting user password hash
        $passwordHash = $this->getPasswordHash($login);

        // Verifying password
        $hashToVerify = $passwordHash;
        if ($hashToVerify === null) {
            $hashToVerify = PasswordHasher::dummyHash();
        }

        $hashMatches = password_verify($password, $hashToVerify);
        if ($passwordHash === null || !$hashMatches) {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            $this->securityAuditLogger->authentication(
                $request,
                SecurityAuditLogger::AUTH_PASSWORD,
                SecurityAuditLogger::OUTCOME_FAILURE,
                login: $login,
            );

            return $this->createAjaxErrorLoginPasswordResponse();
        }

        if (PasswordHasher::needsRehash($passwordHash)) {
            $this->updatePasswordHash($login, $password);
        }

        $this->loginRateLimiter->clear($clientIp, $login);

        // Everything is Ok.
        return $this->successLogin(
            $request,
            $login,
            !$request->request->getBoolean('remember_me'),
            SecurityAuditLogger::AUTH_PASSWORD,
            $this->getUserId($login),
        );
    }

    /**
     * @throws DbLayerException
     */
    private function getPasswordHash(string $login): ?string
    {
        $result = $this->dbLayer
            ->select('password')
            ->from('users')
            ->where('login = :login')
            ->setParameter('login', $login)
            ->execute()
        ;

        $row = $result->fetchRow();

        return $row === false ? null : (string)$row[0];
    }

    /** @throws DbLayerException */
    private function getUserId(string $login): ?int
    {
        $userId = $this->dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')
            ->setParameter('login', $login)
            ->execute()
            ->result()
        ;

        return is_numeric($userId) ? (int)$userId : null;
    }

    /**
     * @throws DbLayerException
     */
    private function updatePasswordHash(string $login, string $password): void
    {
        $newHash = PasswordHasher::hash($password);

        $this->dbLayer
            ->update('users')
            ->set('password', ':password')->setParameter('password', $newHash)
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function deleteSession(string $sessionId): void
    {
        $this->dbLayer
            ->delete('users_online')
            ->where('challenge = :challenge')
            ->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function successLogin(
        Request $request,
        string $login,
        bool $temporary,
        string $authMethod,
        ?int $userId = null,
    ): JsonResponse
    {
        $time          = time();
        $sessionId     = $this->createSessionToken($temporary, $time);
        $commentCookie = bin2hex(random_bytes(32));

        // Create user session
        // TODO check unique constraint violation
        $this->dbLayer
            ->insert('users_online')
            ->setValue('login', ':login')->setParameter('login', $login)
            ->setValue('challenge', ':challenge')->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->setValue('time', ':time')->setParameter('time', $time)
            ->setValue('audience', ':audience')->setParameter('audience', SessionAudience::ADMIN->value)
            ->setValue('ua', ':ua')->setParameter('ua', $request->headers->get('User-Agent'))
            ->setValue('ip', ':ip')->setParameter('ip', $request->getClientIp())
            ->setValue('comment_cookie', ':comment_cookie')->setParameter('comment_cookie', AuthTokenHasher::comment($commentCookie))
            ->execute()
        ;

        $response      = new JsonResponse(['success' => true]);
        $secureCookies = $this->shouldUseSecureCookies($request);

        $expiresAt = $temporary ? 0 : $time + self::PERSISTENT_SESSION_LIFETIME;
        $response->headers->setCookie($this->createAdminCookie($sessionId, $secureCookies, $expiresAt));
        $response->headers->setCookie($this->createCommentCookie($commentCookie, $secureCookies, $expiresAt));

        $this->securityAuditLogger->authentication(
            $request,
            $authMethod,
            SecurityAuditLogger::OUTCOME_SUCCESS,
            $userId,
            $login,
        );

        return $response;
    }

    private function createUnauthorizedResponse(Request $request): Response
    {
        if ($request->isXmlHttpRequest() || $request->attributes->get(self::FORCE_AJAX_RESPONSE) === true) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('Lost session'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->createLoginFormResponse();
    }

    private function createAccessDeniedResponse(Request $request): Response
    {
        if ($request->isXmlHttpRequest() || $request->attributes->get(self::FORCE_AJAX_RESPONSE) === true) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('No permission'),
            ], Response::HTTP_FORBIDDEN);
        }

        return new Response(
            $this->templateRenderer->render('_admin/templates/access-denied.php.inc'),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function createAudienceDeniedResponse(Request $request): Response
    {
        $response = $this->createAccessDeniedResponse($request);
        $response->headers->setCookie($this->createAdminCookie('', $this->shouldUseSecureCookies($request)));

        return $response;
    }

    private function createAjaxErrorLoginPasswordResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('Error login page'),
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function createRateLimitedLoginResponse(int $retryAfter): JsonResponse
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('Too many login attempts'),
        ], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string)$retryAfter);

        return $response;
    }

    private function createLoginFormResponse(string $errorMessage = ''): Response
    {
        $content = $this->templateRenderer->render('_admin/templates/login.php.inc', [
            'errorMessage' => $errorMessage,
        ]);

        return new Response($content);
    }

    /**
     * @throws DbLayerException
     */
    private function checkAndUpdateCurrentUserSession(Request $request, string $sessionId): string
    {
        $this->permissionChecker->clearState();

        // Check if the session still exists.
        $result = $this->dbLayer
            ->select('login, time, audience')
            ->from('users_online')
            ->where('challenge = :challenge')->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->execute()
        ;
        $row = $result->fetchRow();
        // SQLite cannot upgrade a WAL read snapshot after another connection has written.
        // Finish the one-row lookup before refreshing the session so the UPDATE starts a
        // new transaction and busy_timeout can serialize it with shutdown work.
        $result->freeResult();
        if ($row === false) {
            return self::SESSION_STATUS_LOST;
        }

        [$loginValue, $timeValue, $audienceValue] = $row;
        $login    = (string)$loginValue;
        $time     = (int)$timeValue;
        $audience = (string)$audienceValue;

        $now = time();

        $issuedAt          = $this->sessionIssuedAt($sessionId);
        $persistent       = str_starts_with($sessionId, 'p');
        $absoluteLifetime = $persistent ? self::PERSISTENT_SESSION_LIFETIME : self::TEMPORARY_SESSION_LIFETIME;
        $idleTimeout      = $persistent ? self::PERSISTENT_SESSION_IDLE_TIMEOUT : self::TEMPORARY_SESSION_IDLE_TIMEOUT;
        if ($issuedAt === null || $now >= $issuedAt + $absoluteLifetime || $now >= $time + $idleTimeout) {
            return self::SESSION_STATUS_LOST;
        }

        if ($audience !== SessionAudience::ADMIN->value) {
            return self::SESSION_STATUS_FORBIDDEN;
        }

        // Ok, we keep it fresh every 5 seconds.
        if ($now > $time + 5) {
            $this->touchSession($request, $sessionId);
        }

        $this->permissionChecker->setUser($this->getUserInfo($login));

        return self::SESSION_STATUS_OK;
    }

    private function shouldUseSecureCookies(Request $request): bool
    {
        /*
         * Register can run on plain HTTP, so Secure cookies cannot be enabled unconditionally.
         * Still, if the current installation is known to use HTTPS, cookies must not be
         * allowed to leak over HTTP. We treat HTTPS as enabled when:
         *
         * - force_admin_https is enabled and the admin session is explicitly HTTPS-only;
         * - the current request is already HTTPS;
         * - base_url is configured as HTTPS, which is the webmaster's canonical-site setting.
         */
        return $this->forceAdminHttps || $request->isSecure() || str_starts_with(strtolower($this->baseUrl), 'https://');
    }

    private function configuredOrigin(string $scheme): string
    {
        $parsedBaseUrl = parse_url($this->baseUrl);
        if (!\is_array($parsedBaseUrl) || !isset($parsedBaseUrl['host']) || $parsedBaseUrl['host'] === '') {
            throw new \RuntimeException('Unable to construct a configured admin URL.');
        }

        return $scheme . '://' . $parsedBaseUrl['host']
            . (isset($parsedBaseUrl['port']) ? ':' . $parsedBaseUrl['port'] : '');
    }

    /**
     * Special cookie to mark that a user is logged in.
     * If this user has a permission, his comment will be published even in pre-moderation mode.
     */
    private function createAdminCookie(string $value, bool $secure, int $expiresAt = 0): Cookie
    {
        return Cookie::create(
            name: $this->cookieName,
            value: $value,
            expire: $value === '' ? 1 : $expiresAt,
            path: $this->basePath . '/_admin/',
            secure: $secure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    private function createCommentCookie(string $value, bool $secure, int $expiresAt): Cookie
    {
        return Cookie::create(
            name: $this->cookieName . '_c',
            value: $value,
            expire: $value === '' ? 1 : $expiresAt,
            path: rtrim($this->basePath, '/') . '/',
            secure: $secure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function createSessionToken(bool $temporary, int $issuedAt): string
    {
        return ($temporary ? 't' : 'p') . sprintf('%08x', $issuedAt) . bin2hex(random_bytes(32));
    }

    private function sessionIssuedAt(string $sessionId): ?int
    {
        if (preg_match(self::SESSION_TOKEN_PATTERN, $sessionId, $matches) !== 1) {
            return null;
        }

        $issuedAt = (int)hexdec($matches[2]);

        return $issuedAt > time() + 300 ? null : $issuedAt;
    }

    private function createActionCsrfToken(string $sessionId, string $purpose): string
    {
        return hash_hmac('sha256', "admin-action\0" . $purpose, $sessionId);
    }

    private function logoutCsrfTokenMatches(string $sessionId, string $candidate): bool
    {
        return $sessionId !== ''
            && AdminMutationGuard::tokensMatch(
                $this->createActionCsrfToken($sessionId, 'logout'),
                $candidate,
            );
    }

}
