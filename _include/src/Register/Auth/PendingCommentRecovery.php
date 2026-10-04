<?php

declare(strict_types = 1);

namespace Register\Auth;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A browser-owned recovery credential, deliberately separate from the emailed sign-in token. */
final readonly class PendingCommentRecovery
{
    public const string COOKIE_NAME = 'register_pending_comments';

    private function __construct(private string $ownerToken, public string $draftId)
    {
    }

    public static function create(Request $request): self
    {
        return new self(self::ownerToken($request) ?? self::randomToken(), bin2hex(random_bytes(16)));
    }

    public static function fromRequest(Request $request): ?self
    {
        $ownerToken = self::ownerToken($request);
        $draftId = $request->isMethod(Request::METHOD_POST)
            ? $request->request->getString('draft')
            : $request->query->getString('draft');
        if ($ownerToken === null || preg_match('/^[a-f0-9]{32}$/D', $draftId) !== 1) {
            return null;
        }

        return new self($ownerToken, $draftId);
    }

    public function hash(): string
    {
        return hash_hmac('sha256', "pending-comment\0" . $this->draftId, $this->ownerToken);
    }

    public function formToken(): string
    {
        return hash_hmac('sha256', "pending-comment-form\0" . $this->draftId, $this->ownerToken);
    }

    public function matchesFormToken(string $candidate): bool
    {
        return hash_equals($this->formToken(), $candidate);
    }

    public function remember(Response $response, Request $request, string $path): void
    {
        $response->headers->setCookie(Cookie::create(
            self::COOKIE_NAME,
            $this->ownerToken,
            path: $path,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    private static function ownerToken(Request $request): ?string
    {
        $token = $request->cookies->getString(self::COOKIE_NAME);

        return preg_match('/^[A-Za-z0-9_-]{48}$/D', $token) === 1 ? $token : null;
    }
}
