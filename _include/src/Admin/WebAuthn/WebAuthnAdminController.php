<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\WebAuthn;

use Psr\Log\LoggerInterface;
use S2\AdminYard\Translator;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\LoginRateLimiter;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\Audit\SecurityAuditLogger;
use S2\Cms\Security\Http\AdminMutationGuard;
use S2\Cms\Security\WebAuthn\RecoveryCodeRepository;
use S2\Cms\Security\WebAuthn\WebAuthnCredentialRepository;
use S2\Cms\Security\WebAuthn\WebAuthnService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WebAuthnAdminController
{
    public const string ACTION_AUTH_OPTIONS = 'webauthn_auth_options';

    public const string ACTION_AUTH_FINISH = 'webauthn_auth_finish';

    public const string ACTION_RECOVERY_LOGIN = 'webauthn_recovery_login';

    public const string ACTION_REGISTER_OPTIONS = 'webauthn_register_options';

    public const string ACTION_REGISTER_FINISH = 'webauthn_register_finish';

    public const string ACTION_DELETE = 'webauthn_delete';

    public const string ACTION_RECOVERY_REGENERATE = 'webauthn_recovery_regenerate';

    private const string CSRF_REGISTER = 'webauthn-register';

    private const string CSRF_DELETE = 'webauthn-delete';

    private const string CSRF_RECOVERY = 'webauthn-recovery';

    public function __construct(
        private WebAuthnService              $webAuthnService,
        private WebAuthnCredentialRepository $credentialRepository,
        private RecoveryCodeRepository       $recoveryCodeRepository,
        private AuthManager                  $authManager,
        private PermissionChecker            $permissionChecker,
        private LoginRateLimiter             $loginRateLimiter,
        private Translator                   $translator,
        private LoggerInterface              $logger,
        private SecurityAuditLogger          $securityAuditLogger,
        private AdminMutationGuard           $mutationGuard,
        private string                       $basePath,
        private string                       $cookieName,
        private bool                         $secureAdmin,
    ) {
    }

    public function isPublicAction(Request $request): bool
    {
        return \in_array($this->action($request), [
            self::ACTION_AUTH_OPTIONS,
            self::ACTION_AUTH_FINISH,
            self::ACTION_RECOVERY_LOGIN,
        ], true);
    }

    public function isAuthenticatedAction(Request $request): bool
    {
        return \in_array($this->action($request), [
            self::ACTION_REGISTER_OPTIONS,
            self::ACTION_REGISTER_FINISH,
            self::ACTION_DELETE,
            self::ACTION_RECOVERY_REGENERATE,
        ], true);
    }

    public function handlePublic(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if ($this->secureAdmin && !$request->isSecure()) {
            return $this->error('HTTPS is required.', Response::HTTP_BAD_REQUEST);
        }

        $rateLimitKey = $this->publicRateLimitKey($request);
        $retryAfter = $this->loginRateLimiter->retryAfter($request->getClientIp() ?? '', $rateLimitKey);
        if ($retryAfter > 0) {
            $this->securityAuditLogger->authentication(
                $request,
                $this->authenticationMethod($request),
                SecurityAuditLogger::OUTCOME_RATE_LIMITED,
                login: $this->authenticationLogin($request),
            );
            $response = $this->error('Too many login attempts. Try again later.', Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string)$retryAfter);

            return $response;
        }

        try {
            $response = match ($this->action($request)) {
                self::ACTION_AUTH_OPTIONS => $this->authenticationOptions($request),
                self::ACTION_AUTH_FINISH => $this->authenticationFinish($request),
                self::ACTION_RECOVERY_LOGIN => $this->recoveryLogin($request),
                default => new JsonResponse(['success' => false], Response::HTTP_NOT_FOUND),
            };
            if ($this->action($request) !== self::ACTION_AUTH_OPTIONS) {
                $this->loginRateLimiter->clear($request->getClientIp() ?? '', $rateLimitKey);
            }

            return $response;
        } catch (\Throwable $throwable) {
            if ($this->action($request) !== self::ACTION_AUTH_OPTIONS) {
                $this->loginRateLimiter->recordFailure($request->getClientIp() ?? '', $rateLimitKey);
                $this->securityAuditLogger->authentication(
                    $request,
                    $this->authenticationMethod($request),
                    SecurityAuditLogger::OUTCOME_FAILURE,
                    login: $this->authenticationLogin($request),
                );
            }

            $this->logger->notice('A public WebAuthn operation was rejected.', [
                'action'    => $this->action($request),
                'exception' => $throwable,
            ]);

            return $this->error('The passkey request could not be verified. Start again.', Response::HTTP_UNAUTHORIZED);
        }
    }

    public function handleAuthenticated(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            $this->auditCredentialOperation($request, SecurityAuditLogger::OUTCOME_DENIED);

            return $this->error('No permission.', Response::HTTP_FORBIDDEN);
        }

        try {
            return match ($this->action($request)) {
                self::ACTION_REGISTER_OPTIONS => $this->registrationOptions($request),
                self::ACTION_REGISTER_FINISH => $this->registrationFinish($request),
                self::ACTION_DELETE => $this->deleteCredential($request),
                self::ACTION_RECOVERY_REGENERATE => $this->regenerateRecoveryCodes($request),
                default => new JsonResponse(['success' => false], Response::HTTP_NOT_FOUND),
            };
        } catch (\InvalidArgumentException $exception) {
            $this->auditCredentialOperation($request, SecurityAuditLogger::OUTCOME_FAILURE);

            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $throwable) {
            $this->auditCredentialOperation($request, SecurityAuditLogger::OUTCOME_FAILURE);
            $this->logger->notice('An authenticated WebAuthn operation was rejected.', [
                'action'    => $this->action($request),
                'user_id'   => $this->permissionChecker->getUserId(),
                'exception' => $throwable,
            ]);

            return $this->error('The security operation could not be verified. Start again.', Response::HTTP_FORBIDDEN);
        }
    }

    public function registerCsrfToken(): string
    {
        return $this->authManager->getActionCsrfToken(self::CSRF_REGISTER);
    }

    public function deleteCsrfToken(): string
    {
        return $this->authManager->getActionCsrfToken(self::CSRF_DELETE);
    }

    public function recoveryCsrfToken(): string
    {
        return $this->authManager->getActionCsrfToken(self::CSRF_RECOVERY);
    }

    public function isAvailable(): bool
    {
        return $this->webAuthnService->isAvailable();
    }

    private function authenticationOptions(Request $request): JsonResponse
    {
        $result = $this->webAuthnService->beginAuthentication($request, $request->request->getBoolean('remember_me'));
        $response = new JsonResponse(['success' => true, 'publicKey' => $result['options']]);
        $response->headers->setCookie($this->ceremonyCookie($result['ceremony']->token, $request));

        return $response;
    }

    private function authenticationFinish(Request $request): JsonResponse
    {
        $result = $this->webAuthnService->finishAuthentication(
            $request,
            $request->cookies->get($this->ceremonyCookieName(), ''),
            $this->credentialJson($request),
        );
        $response = $this->authManager->loginVerifiedUser(
            $request,
            $result['user_id'],
            $result['remember'],
            SecurityAuditLogger::AUTH_PASSKEY,
        );
        $response->headers->setCookie($this->ceremonyCookie('', $request));

        return $response;
    }

    private function recoveryLogin(Request $request): JsonResponse
    {
        $login = $request->request->getString('login');
        $userId = $this->recoveryCodeRepository->consume($login, $request->request->getString('recovery_code'));
        if ($userId === null) {
            throw new \RuntimeException('Invalid recovery code.');
        }

        return $this->authManager->loginVerifiedUser(
            $request,
            $userId,
            $request->request->getBoolean('remember_me'),
            SecurityAuditLogger::AUTH_RECOVERY_CODE,
        );
    }

    private function registrationOptions(Request $request): JsonResponse
    {
        $this->requireCsrf($request, self::CSRF_REGISTER);
        $this->requirePassword($request);
        $userId = $this->requireUserId();
        $result = $this->webAuthnService->beginRegistration(
            $request,
            $userId,
            $this->authManager->getCurrentSessionStorageKey(),
            $request->request->getString('name'),
        );
        $response = new JsonResponse(['success' => true, 'publicKey' => $result['options']]);
        $response->headers->setCookie($this->ceremonyCookie($result['ceremony']->token, $request));

        return $response;
    }

    private function registrationFinish(Request $request): JsonResponse
    {
        $credential = $this->webAuthnService->finishRegistration(
            $request,
            $request->cookies->get($this->ceremonyCookieName(), ''),
            $this->authManager->getCurrentSessionStorageKey(),
            $this->credentialJson($request),
        );
        $response = new JsonResponse([
            'success' => true,
            'credential' => [
                'hash'       => $credential->hash,
                'name'       => $credential->name,
                'created_at' => $credential->createdAt,
            ],
        ]);
        $response->headers->setCookie($this->ceremonyCookie('', $request));
        $this->securityAuditLogger->credentialChanged(
            $this->requireUserId(),
            'passkey_register',
            SecurityAuditLogger::OUTCOME_SUCCESS,
        );

        return $response;
    }

    private function deleteCredential(Request $request): JsonResponse
    {
        $this->requireCsrf($request, self::CSRF_DELETE);
        $this->requirePassword($request);
        $hash = $request->request->getString('credential_hash');
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Invalid passkey identifier.');
        }

        $userId  = $this->requireUserId();
        $deleted = $this->credentialRepository->delete($userId, $hash);
        $this->securityAuditLogger->credentialChanged(
            $userId,
            'passkey_delete',
            $deleted ? SecurityAuditLogger::OUTCOME_SUCCESS : SecurityAuditLogger::OUTCOME_FAILURE,
        );

        return new JsonResponse(['success' => $deleted]);
    }

    private function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->requireCsrf($request, self::CSRF_RECOVERY);
        $this->requirePassword($request);

        $userId = $this->requireUserId();
        $response = new JsonResponse([
            'success' => true,
            'codes'   => $this->recoveryCodeRepository->regenerate($userId),
        ]);
        $this->securityAuditLogger->credentialChanged(
            $userId,
            'recovery_codes_regenerate',
            SecurityAuditLogger::OUTCOME_SUCCESS,
        );

        return $response;
    }

    private function requirePassword(Request $request): void
    {
        if (!$this->authManager->verifyCurrentPassword($request, $request->request->getString('password'))) {
            throw new \RuntimeException('The current password is incorrect.');
        }
    }

    private function requireCsrf(Request $request, string $purpose): void
    {
        if (!$this->mutationGuard->hasValidCsrfToken(
            $request,
            $this->authManager->getActionCsrfToken($purpose),
        )) {
            throw new \RuntimeException('Invalid security token.');
        }
    }

    private function requireUserId(): int
    {
        return $this->permissionChecker->getUserId()
            ?? throw new \RuntimeException('No authenticated user.');
    }

    private function credentialJson(Request $request): string
    {
        $payload = $request->toArray();
        $credential = $payload['credential'] ?? null;
        if (!\is_array($credential)) {
            throw new \InvalidArgumentException('The passkey response is missing.');
        }

        return json_encode($credential, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function ceremonyCookie(string $value, Request $request): Cookie
    {
        return Cookie::create(
            name: $this->ceremonyCookieName(),
            value: $value,
            expire: $value === '' ? 1 : 0,
            path: rtrim($this->basePath, '/') . '/_admin/',
            secure: $this->secureAdmin || $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    private function ceremonyCookieName(): string
    {
        return $this->cookieName . '_webauthn';
    }

    private function action(Request $request): string
    {
        return $request->query->getString('action');
    }

    private function publicRateLimitKey(Request $request): string
    {
        return $this->action($request) === self::ACTION_RECOVERY_LOGIN
            ? $request->request->getString('login')
            : 'webauthn-passkey';
    }

    private function authenticationMethod(Request $request): string
    {
        return $this->action($request) === self::ACTION_RECOVERY_LOGIN
            ? SecurityAuditLogger::AUTH_RECOVERY_CODE
            : SecurityAuditLogger::AUTH_PASSKEY;
    }

    private function authenticationLogin(Request $request): string
    {
        return $this->action($request) === self::ACTION_RECOVERY_LOGIN
            ? $request->request->getString('login')
            : '';
    }

    private function auditCredentialOperation(Request $request, string $outcome): void
    {
        $userId = $this->permissionChecker->getUserId();
        $operation = match ($this->action($request)) {
            self::ACTION_REGISTER_OPTIONS, self::ACTION_REGISTER_FINISH => 'passkey_register',
            self::ACTION_DELETE => 'passkey_delete',
            self::ACTION_RECOVERY_REGENERATE => 'recovery_codes_regenerate',
            default => null,
        };
        if ($userId !== null && $operation !== null) {
            $this->securityAuditLogger->credentialChanged($userId, $operation, $outcome);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
