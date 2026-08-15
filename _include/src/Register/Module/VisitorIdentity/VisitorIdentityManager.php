<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use S2\Cms\Config\DynamicConfigProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorIdentityManager
{
    private const int COOKIE_TTL = 365 * 24 * 60 * 60;

    private string $cookieName;

    private string $cookiePath;

    public function __construct(
        private VisitorIdentityRepository $repository,
        private DynamicConfigProvider      $configProvider,
        string                            $cookieName,
        string                            $basePath,
    ) {
        $this->cookieName = $cookieName . '_visitor';
        $this->cookiePath = rtrim($basePath, '/') . '/';
    }

    public function resolve(Request $request, ?string $storageToken): ResolvedVisitor
    {
        $visitorId = null;
        $source    = 'new';

        $cookieToken = $request->cookies->get($this->cookieName);
        if (\is_string($cookieToken)) {
            $visitorId = $this->visitorIdFromToken($cookieToken);
            if ($visitorId !== null) {
                $source = 'cookie';
            }
        }

        if ($visitorId === null && $storageToken !== null) {
            $visitorId = $this->visitorIdFromToken($storageToken);
            if ($visitorId !== null) {
                $source = 'storage';
            }
        }

        $visitorId ??= bin2hex(random_bytes(16));
        $now = time();
        $this->repository->touchVisitor($visitorId, $now);

        return new ResolvedVisitor($visitorId, $this->tokenFor($visitorId), $source);
    }

    public function visitorIdFromRequest(Request $request): ?string
    {
        $token = $request->cookies->get($this->cookieName);

        return \is_string($token) ? $this->visitorIdFromToken($token) : null;
    }

    public function visitorIdFromToken(string $token): ?string
    {
        if (substr_count($token, '.') !== 1) {
            return null;
        }

        [$visitorId, $signature] = explode('.', $token, 2);
        if (preg_match('/^[a-f0-9]{32}$/D', $visitorId) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            return null;
        }

        return hash_equals($this->signature($visitorId), $signature) ? $visitorId : null;
    }

    public function createCookie(string $token, Request $request): Cookie
    {
        return Cookie::create(
            name: $this->cookieName,
            value: $token,
            expire: time() + self::COOKIE_TTL,
            path: $this->cookiePath,
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function cookiePath(): string
    {
        return $this->cookiePath;
    }

    private function tokenFor(string $visitorId): string
    {
        return $visitorId . '.' . $this->signature($visitorId);
    }

    private function signature(string $visitorId): string
    {
        return hash_hmac('sha256', "register-visitor\0" . $visitorId, $this->secret());
    }

    private function secret(): string
    {
        try {
            $secret = $this->configProvider->get(Manifest::SECRET_CONFIG_KEY);
        } catch (\LogicException) {
            // A schema update can replace the config file while a long-running PHP process still
            // has the previous version loaded. Refresh it once before treating the install as bad.
            $this->configProvider->regenerate();
            $secret = $this->configProvider->get(Manifest::SECRET_CONFIG_KEY);
        }

        if (!\is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new \LogicException('The anonymous visitor secret is missing or invalid.');
        }

        return $secret;
    }
}
