<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

use S2\Cms\Pdo\DbLayer;

final readonly class WebAuthnChallengeRepository
{
    public const int LIFETIME_SECONDS = 5 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @param array<string, mixed> $context */
    public function issue(
        string $purpose,
        string $binding,
        ?int $userId,
        ?string $sessionHash,
        array $context = [],
        ?int $now = null,
    ): WebAuthnChallenge {
        $now ??= time();
        $this->removeExpired($now);

        $bindingHash = $this->bindingHash($binding);
        $this->dbLayer
            ->delete(WebAuthnSchema::CHALLENGE_TABLE)
            ->where('binding_hash = :binding_hash')->setParameter('binding_hash', $bindingHash)
            ->andWhere('purpose = :purpose')->setParameter('purpose', $purpose)
            ->execute()
        ;

        $token = bin2hex(random_bytes(32));
        $challenge = random_bytes(32);
        $expiresAt = $now + self::LIFETIME_SECONDS;
        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $insert = $this->dbLayer
            ->insert(WebAuthnSchema::CHALLENGE_TABLE)
            ->setValue('token_hash', ':token_hash')->setParameter('token_hash', $this->tokenHash($token))
            ->setValue('purpose', ':purpose')->setParameter('purpose', $purpose)
            ->setValue('challenge', ':challenge')->setParameter('challenge', $this->encode($challenge))
            ->setValue('binding_hash', ':binding_hash')->setParameter('binding_hash', $bindingHash)
            ->setValue('context', ':context')->setParameter('context', $encodedContext)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('expires_at', ':expires_at')->setParameter('expires_at', $expiresAt)
        ;
        if ($userId === null) {
            $insert->setValue('user_id', 'NULL');
        } else {
            $insert->setValue('user_id', ':user_id')->setParameter('user_id', $userId);
        }

        if ($sessionHash === null) {
            $insert->setValue('session_hash', 'NULL');
        } else {
            $insert->setValue('session_hash', ':session_hash')->setParameter('session_hash', $sessionHash);
        }

        $insert->execute();

        return new WebAuthnChallenge($token, $challenge, $userId, $sessionHash, $context, $expiresAt);
    }

    public function consume(string $token, string $purpose, string $binding, ?int $now = null): ?WebAuthnChallenge
    {
        $now ??= time();
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }

        $tokenHash = $this->tokenHash($token);
        $result = $this->dbLayer
            ->select('purpose, challenge, user_id, session_hash, binding_hash, context, expires_at')
            ->from(WebAuthnSchema::CHALLENGE_TABLE)
            ->where('token_hash = :token_hash')->setParameter('token_hash', $tokenHash)
            ->execute()
        ;
        $row = $result->fetchAssoc();
        $result->freeResult();
        if ($row === false) {
            return null;
        }

        $deleted = $this->dbLayer
            ->delete(WebAuthnSchema::CHALLENGE_TABLE)
            ->where('token_hash = :token_hash')->setParameter('token_hash', $tokenHash)
            ->execute()
            ->affectedRows()
        ;
        if (
            $deleted !== 1
            || !hash_equals((string)$row['purpose'], $purpose)
            || !hash_equals((string)$row['binding_hash'], $this->bindingHash($binding))
            || (int)$row['expires_at'] <= $now
        ) {
            return null;
        }

        $context = json_decode((string)$row['context'], true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($context)) {
            throw new \UnexpectedValueException('Invalid stored WebAuthn challenge context.');
        }

        return new WebAuthnChallenge(
            $token,
            $this->decode((string)$row['challenge']),
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['session_hash'] === null ? null : (string)$row['session_hash'],
            $context,
            (int)$row['expires_at'],
        );
    }

    private function removeExpired(int $now): void
    {
        $this->dbLayer
            ->delete(WebAuthnSchema::CHALLENGE_TABLE)
            ->where('expires_at < :expires_at')->setParameter('expires_at', $now)
            ->execute()
        ;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', "webauthn-challenge-token\0" . $token);
    }

    private function bindingHash(string $binding): string
    {
        return hash('sha256', "webauthn-browser-binding\0" . $binding);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Invalid stored WebAuthn challenge.');
        }

        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid stored WebAuthn challenge.');
        }

        return $decoded;
    }
}
