<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use S2\AdminYard\Helper\RandomHelper;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use S2\Cms\Pdo\DbLayerException;

readonly class AuthManager
{
    public const string FORCE_AJAX_RESPONSE = '_force_ajax_response';

    public const int PERSISTENT_SESSION_LIFETIME = 5 * 365 * 86400;

    private const string SESSION_STATUS_LOST     = 'Lost';

    private const string SESSION_STATUS_OK       = 'Ok';

    private const string LEGACY_PASSWORD_PEPPER  = 'Life is not so easy :-)';

    /** A fixed modern hash keeps unknown-user checks comparable to valid-user checks. */
    private const string DUMMY_PASSWORD_HASH = '$2y$12$sEo8P2Bkb56lN9bNRbs6wuAsbAyQjeLBVR8Z1nzkLi03mGY.649mK';

    public function __construct(
        private DbLayer           $dbLayer,
        private PermissionChecker $permissionChecker,
        private RequestStack      $requestStack,
        private TemplateRenderer  $templateRenderer,
        private Translator        $translator,
        private LoginRateLimiter  $loginRateLimiter,
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
            $this->deleteSession($sessionId);
            $baseUrl = $this->shouldUseSecureCookies($request)
                ? (preg_replace('/^http:/i', 'https:', $this->baseUrl) ?? $this->baseUrl)
                : $this->baseUrl;
            $response      = new RedirectResponse(rtrim($baseUrl, '/') . '/_admin/index.php');
            $secureCookies = $this->shouldUseSecureCookies($request);
            $response->headers->setCookie(Cookie::create(
                name: $this->cookieName,
                value: '',
                path: $this->basePath . '/_admin/',
                secure: $secureCookies,
            ));
            $response->headers->setCookie($this->createCommentCookie('', $secureCookies));
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
            return new Response($this->templateRenderer->render('_admin/templates/access-denied.php.inc'));
        }

        $status = $this->checkAndUpdateCurrentUserSession($request, $sessionId);
        if ($status !== self::SESSION_STATUS_OK || !$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return new Response($this->templateRenderer->render('_admin/templates/access-denied.php.inc'));
        }

        return null;
    }

    public function getCurrentSessionId(): string
    {
        $request = $this->requestStack->getMainRequest();
        return $request instanceof \Symfony\Component\HttpFoundation\Request ? $request->cookies->get($this->cookieName, '') : '';
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
            ->setParameter('challenge', $this->getCurrentSessionId())
            ->execute()
        ;

        return (int)$result->result();
    }

    /**
     * Renews persistent login cookies after an authenticated admin request. Legacy session IDs are
     * treated as persistent, so an existing login is upgraded without asking for a password again.
     *
     * @throws DbLayerException
     */
    public function renewPersistentCookies(Request $request, Response $response): void
    {
        $sessionId = $request->cookies->get($this->cookieName, '');
        if ($sessionId === '' || str_starts_with($sessionId, 't')) {
            return;
        }

        $commentCookie = $this->dbLayer
            ->select('comment_cookie')
            ->from('users_online')
            ->where('challenge = :challenge')->setParameter('challenge', $sessionId)
            ->execute()
            ->result()
        ;
        if (!\is_string($commentCookie) || $commentCookie === '') {
            return;
        }

        $secureCookies = $this->shouldUseSecureCookies($request);
        $response->headers->setCookie(Cookie::create(
            name: $this->cookieName,
            value: $sessionId,
            expire: time() + self::PERSISTENT_SESSION_LIFETIME,
            path: $this->basePath . '/_admin/',
            secure: $secureCookies,
        ));
        $response->headers->setCookie($this->createCommentCookie($commentCookie, $secureCookies));
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
                return new Response($this->templateRenderer->render('_admin/templates/access-denied.php.inc'));
            }

            return null;
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
        $response->headers->setCookie(Cookie::create(
            name: $this->cookieName,
            value: '',
            path: $this->basePath . '/_admin/',
            secure: $secureCookies,
        ));
        $response->headers->setCookie($this->createCommentCookie('', $secureCookies));

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
            ->setParameter('challenge', $sessionId)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function processLoginForm(Request $request): Response
    {
        $login    = $request->request->getString('login');
        $password = $request->request->getString('pass');
        $clientIp = $request->getClientIp() ?? '';

        $retryAfter = $this->loginRateLimiter->retryAfter($clientIp, $login);
        if ($retryAfter > 0) {
            return $this->createRateLimitedLoginResponse($retryAfter);
        }

        if ($login === '') {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            return $this->createAjaxErrorLoginPasswordResponse();
        }

        if ($password === '') {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            return $this->createAjaxErrorLoginPasswordResponse();
        }

        // Getting user password hash
        $passwordHash = $this->getPasswordHash($login);

        // Verifying password
        $hashToVerify = $passwordHash;
        if ($hashToVerify === null) {
            $hashToVerify = self::DUMMY_PASSWORD_HASH;
        }

        $hashMatches = password_verify($password, $hashToVerify);
        $oldHashMatches = $passwordHash !== null
            && hash_equals($passwordHash, md5($password . self::LEGACY_PASSWORD_PEPPER));
        if ($passwordHash === null || (!$hashMatches && !$oldHashMatches)) {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            return $this->createAjaxErrorLoginPasswordResponse();
        }

        if (!$hashMatches || password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $this->updatePasswordHash($login, $password);
        }

        $this->loginRateLimiter->clear($clientIp, $login);

        // Everything is Ok.
        return $this->successLogin($request, $login, $request->request->getBoolean('foreign_computer'));
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

    /**
     * @throws DbLayerException
     */
    private function updatePasswordHash(string $login, string $password): void
    {
        $newHash = password_hash($password, PASSWORD_DEFAULT);

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
            ->setParameter('challenge', $sessionId)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function successLogin(Request $request, string $login, bool $foreignComputer): JsonResponse
    {
        $time          = time();
        $sessionId     = ($foreignComputer ? 't' : 'p') . substr(RandomHelper::getRandomHexString32(), 1);
        $commentCookie = RandomHelper::getRandomHexString32();

        // Create user session
        // TODO check unique constraint violation
        $this->dbLayer
            ->insert('users_online')
            ->setValue('login', ':login')->setParameter('login', $login)
            ->setValue('challenge', ':challenge')->setParameter('challenge', $sessionId)
            ->setValue('time', ':time')->setParameter('time', $time)
            ->setValue('ua', ':ua')->setParameter('ua', $request->headers->get('User-Agent'))
            ->setValue('ip', ':ip')->setParameter('ip', $request->getClientIp())
            ->setValue('comment_cookie', ':comment_cookie')->setParameter('comment_cookie', $commentCookie)
            ->execute()
        ;

        $response      = new JsonResponse(['success' => true]);
        $secureCookies = $this->shouldUseSecureCookies($request);

        $response->headers->setCookie(Cookie::create(
            name: $this->cookieName,
            value: $sessionId,
            expire: $foreignComputer ? 0 : $time + self::PERSISTENT_SESSION_LIFETIME,
            path: $this->basePath . '/_admin/',
            secure: $secureCookies,
        ));
        $response->headers->setCookie($this->createCommentCookie($commentCookie, $secureCookies, $foreignComputer));

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
        // Check if the session still exists.
        $result = $this->dbLayer
            ->select('login, time')
            ->from('users_online')
            ->where('challenge = :challenge')->setParameter('challenge', $sessionId)
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

        [$loginValue, $timeValue] = $row;
        $login = (string)$loginValue;
        $time  = (int)$timeValue;

        $now = time();

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
         * S2 can run on plain HTTP, so Secure cookies cannot be enabled unconditionally.
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
    private function createCommentCookie(string $value, bool $secure, bool $foreignComputer = false): Cookie
    {
        return Cookie::create(
            name: $this->cookieName . '_c',
            value: $value,
            expire: $value !== '' && !$foreignComputer ? self::PERSISTENT_SESSION_LIFETIME + time() : 0,
            path: rtrim($this->basePath, '/') . '/',
            secure: $secure,
        );
    }
}
