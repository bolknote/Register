<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class CommentFormTokenManager
{
    public const int FORM_TOKEN_TTL = 12 * 60 * 60;

    /** @var list<string> */
    private const array MUTABLE_FIELDS = [
        'email',
        'homepage',
        'id',
        'name',
        'parent_id',
        'preview',
        'reply_name',
        'reply_number',
        'subscribed',
        'text',
    ];

    private const int FUTURE_LEEWAY = 60;

    private const int VISITOR_COOKIE_TTL = 30 * 24 * 60 * 60;

    private string $visitorCookieName;

    private string $cookiePath;

    public function __construct(
        private SpamIdentityHasher $hasher,
        private DbLayer            $dbLayer,
        string                     $cookieName,
        string                     $basePath,
    ) {
        $this->visitorCookieName = $cookieName . '_antispam';
        $this->cookiePath        = rtrim($basePath, '/') . '/';
    }

    public function getOrCreateVisitorToken(Request $request): string
    {
        $existing = $request->cookies->get($this->visitorCookieName);
        if (\is_string($existing) && $this->visitorId($existing) !== null) {
            return $existing;
        }

        $visitorId = bin2hex(random_bytes(16));

        return $visitorId . '.' . $this->hasher->sign('comment-visitor', $visitorId);
    }

    public function createVisitorCookie(string $visitorToken, Request $request): Cookie
    {
        return Cookie::create(
            name: $this->visitorCookieName,
            value: $visitorToken,
            expire: time() + self::VISITOR_COOKIE_TTL,
            path: $this->cookiePath,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * @throws \JsonException
     */
    public function issue(string $targetPath, string $visitorToken, ?int $now = null): string
    {
        $visitorId = $this->visitorId($visitorToken)
            ?? throw new \InvalidArgumentException('A valid antispam visitor token is required.');

        $payload = $this->base64UrlEncode(json_encode([
            'v'       => 1,
            'iat'     => $now ?? time(),
            'nonce'   => bin2hex(random_bytes(16)),
            'target'  => $targetPath,
            'visitor' => $visitorId,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->hasher->sign('comment-form', $payload);
    }

    /**
     * Returns a token-bound name for every meaningful comment form field.
     * A fresh form token therefore produces a fresh form shape without making
     * JavaScript a requirement for posting a comment.
     *
     * @return array<string, string>
     */
    public function fieldNames(string $token): array
    {
        $fieldNames = [];
        foreach (self::MUTABLE_FIELDS as $field) {
            $fieldNames[$field] = 'cf_' . substr(
                $this->hasher->sign('comment-form-field:' . $field, $token),
                0,
                24,
            );
        }

        return $fieldNames;
    }

    /**
     * @throws DbLayerException
     */
    public function validateAndMaybeConsume(
        string $token,
        Request $request,
        bool   $consume,
        ?int   $now = null,
    ): CommentFormTokenValidation {
        if (\strlen($token) > 2048 || substr_count($token, '.') !== 1) {
            return CommentFormTokenValidation::invalid('malformed');
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);
        $expectedSignature = $this->hasher->sign('comment-form', $encodedPayload);
        if (!hash_equals($expectedSignature, $signature)) {
            return CommentFormTokenValidation::invalid('signature');
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return CommentFormTokenValidation::invalid('encoding');
        }

        try {
            $payload = json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return CommentFormTokenValidation::invalid('json');
        }

        if (!\is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || !\is_int($payload['iat'] ?? null)
            || !\is_string($payload['nonce'] ?? null)
            || preg_match('#^[0-9a-f]{32}$#', $payload['nonce']) !== 1
            || !\is_string($payload['target'] ?? null)
            || !\is_string($payload['visitor'] ?? null)
            || preg_match('#^[0-9a-f]{32}$#', $payload['visitor']) !== 1
        ) {
            return CommentFormTokenValidation::invalid('claims');
        }

        if (!hash_equals($request->getPathInfo(), $payload['target'])) {
            return CommentFormTokenValidation::invalid('target');
        }

        $visitorToken = $request->cookies->get($this->visitorCookieName);
        $visitorId    = \is_string($visitorToken) ? $this->visitorId($visitorToken) : null;
        if ($visitorId === null || !hash_equals($visitorId, $payload['visitor'])) {
            return CommentFormTokenValidation::invalid('visitor');
        }

        $now ??= time();
        $age = $now - $payload['iat'];
        if ($age < -self::FUTURE_LEEWAY) {
            return CommentFormTokenValidation::invalid('future');
        }

        if ($age > self::FORM_TOKEN_TTL) {
            return CommentFormTokenValidation::invalid('expired');
        }

        if ($consume && !$this->consumeNonce($payload['nonce'], $payload['iat'] + self::FORM_TOKEN_TTL)) {
            return CommentFormTokenValidation::invalid('replayed');
        }

        return CommentFormTokenValidation::valid(max(0, $age), $payload['visitor']);
    }

    private function visitorId(string $visitorToken): ?string
    {
        if (substr_count($visitorToken, '.') !== 1) {
            return null;
        }

        [$visitorId, $signature] = explode('.', $visitorToken, 2);
        if (preg_match('#^[0-9a-f]{32}$#', $visitorId) !== 1) {
            return null;
        }

        return hash_equals($this->hasher->sign('comment-visitor', $visitorId), $signature)
            ? $visitorId
            : null;
    }

    /**
     * @throws DbLayerException
     */
    private function consumeNonce(string $nonce, int $expiresAt): bool
    {
        $result = $this->dbLayer
            ->insert('spam_form_nonces')
            ->setValue('nonce_hash', ':nonce_hash')->setParameter('nonce_hash', $this->hasher->nonce($nonce))
            ->setValue('expires_at', ':expires_at')->setParameter('expires_at', $expiresAt)
            ->onConflictDoNothing('nonce_hash')
            ->execute()
        ;

        return $result->affectedRows() === 1;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = (4 - \strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return $decoded === false ? null : $decoded;
    }
}
