<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Model\AuthTokenHasher;
use Register\Core\Model\LoginRateLimiter;
use Register\Core\Model\PasswordHasher;
use Register\Core\Model\SessionAudience;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Creates and revokes public sessions without loading the admin application. */
final readonly class PublicSessionManager
{
    private const int PERSISTENT_SESSION_LIFETIME = 30 * 86400;

    public function __construct(
        private DbLayer             $dbLayer,
        private LoginRateLimiter    $loginRateLimiter,
        private SecurityAuditLogger $securityAuditLogger,
        private TranslatorInterface $translator,
        private string              $basePath,
        private string              $baseUrl,
        private string              $cookieName,
        private bool                $forceAdminHttps,
    ) {
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        $login = trim($request->request->getString('login'));
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

            $response = $this->error(
                $this->translator->trans('Too many login attempts. Try again later.'),
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', (string)$retryAfter);

            return $response;
        }

        $user = $login === '' ? null : $this->userCredentials($login);
        $hash = $user === null ? PasswordHasher::dummyHash() : $user['password'];
        $valid = $password !== '' && password_verify($password, $hash);
        if ($user === null || !$valid) {
            $this->loginRateLimiter->recordFailure($clientIp, $login);
            $this->securityAuditLogger->authentication(
                $request,
                SecurityAuditLogger::AUTH_PASSWORD,
                SecurityAuditLogger::OUTCOME_FAILURE,
                login: $login,
            );

            return $this->error($this->translator->trans('Incorrect login or password'), JsonResponse::HTTP_UNAUTHORIZED);
        }

        if (PasswordHasher::needsRehash($user['password'])) {
            $this->dbLayer
                ->update('users')
                ->set('password', ':password')->setParameter('password', PasswordHasher::hash($password))
                ->where('id = :id')->setParameter('id', $user['id'])
                ->execute()
            ;
        }

        $this->loginRateLimiter->clear($clientIp, $login);

        return $this->createSession(
            $request,
            $user['id'],
            $login,
            !$request->request->getBoolean('remember_me'),
            SecurityAuditLogger::AUTH_PASSWORD,
            $user['view'] ? SessionAudience::ADMIN : SessionAudience::PUBLIC,
        );
    }

    public function loginVerifiedUser(Request $request, int $userId, bool $remember, string $authMethod): JsonResponse
    {
        $login = $this->dbLayer
            ->select('login')
            ->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
            ->result()
        ;
        if (!\is_string($login) || $login === '') {
            $this->securityAuditLogger->authentication(
                $request,
                $authMethod,
                SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $this->error($this->translator->trans('Unable to sign in. Please try again.'), JsonResponse::HTTP_UNAUTHORIZED);
        }

        $this->loginRateLimiter->clear($request->getClientIp() ?? '', $login);

        return $this->createSession(
            $request,
            $userId,
            $login,
            !$remember,
            $authMethod,
            SessionAudience::PUBLIC,
        );
    }

    public function logoutCsrfTokenMatches(Request $request, string $candidate): bool
    {
        $commentCookie = $request->cookies->get($this->cookieName . '_c', '');
        if ($commentCookie === '') {
            return false;
        }

        $expected = hash_hmac('sha256', "public-auth\0logout", hash('sha256', $commentCookie));

        return AdminMutationGuard::tokensMatch($expected, $candidate);
    }

    public function logout(Request $request): JsonResponse
    {
        $commentCookie = $request->cookies->get($this->cookieName . '_c', '');
        if ($commentCookie !== '') {
            $this->dbLayer
                ->delete('users_online')
                ->where('comment_cookie = :comment_cookie')
                ->setParameter('comment_cookie', AuthTokenHasher::comment($commentCookie))
                ->execute()
            ;
        }

        $response = new JsonResponse(['success' => true]);
        $secure = $this->shouldUseSecureCookies($request);
        $response->headers->setCookie($this->adminCookie('', $secure));
        $response->headers->setCookie($this->publicCookie('', $secure, 0));

        return $response;
    }

    /** @return array{id: int, password: string, view: bool}|null */
    private function userCredentials(string $login): ?array
    {
        $row = $this->dbLayer
            ->select('id', 'password', 'view')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : [
            'id'       => (int)$row['id'],
            'password' => (string)$row['password'],
            'view'     => (bool)$row['view'],
        ];
    }

    private function createSession(
        Request $request,
        int $userId,
        string $login,
        bool $temporary,
        string $authMethod,
        SessionAudience $audience,
    ): JsonResponse {
        $time = time();
        $sessionId = ($temporary ? 't' : 'p') . sprintf('%08x', $time) . bin2hex(random_bytes(32));
        $commentCookie = bin2hex(random_bytes(32));
        $this->dbLayer
            ->insert('users_online')
            ->setValue('login', ':login')->setParameter('login', $login)
            ->setValue('challenge', ':challenge')->setParameter('challenge', AuthTokenHasher::session($sessionId))
            ->setValue('time', ':time')->setParameter('time', $time)
            ->setValue('audience', ':audience')->setParameter('audience', $audience->value)
            ->setValue('ua', ':ua')->setParameter('ua', $request->headers->get('User-Agent'))
            ->setValue('ip', ':ip')->setParameter('ip', $request->getClientIp())
            ->setValue('comment_cookie', ':comment_cookie')->setParameter('comment_cookie', AuthTokenHasher::comment($commentCookie))
            ->execute()
        ;

        $response = new JsonResponse(['success' => true]);
        $secure = $this->shouldUseSecureCookies($request);
        $expiresAt = $temporary ? 0 : $time + self::PERSISTENT_SESSION_LIFETIME;
        $response->headers->setCookie($this->adminCookie(
            $audience === SessionAudience::ADMIN ? $sessionId : '',
            $secure,
            $expiresAt,
        ));
        $response->headers->setCookie($this->publicCookie($commentCookie, $secure, $expiresAt));

        $this->securityAuditLogger->authentication(
            $request,
            $authMethod,
            SecurityAuditLogger::OUTCOME_SUCCESS,
            $userId,
            $login,
        );

        return $response;
    }

    private function adminCookie(string $value, bool $secure, int $expiresAt = 0): Cookie
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

    private function publicCookie(string $value, bool $secure, int $expiresAt): Cookie
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

    private function shouldUseSecureCookies(Request $request): bool
    {
        return $this->forceAdminHttps
            || $request->isSecure()
            || str_starts_with(strtolower($this->baseUrl), 'https://');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
