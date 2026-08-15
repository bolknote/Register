<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

use S2\Cms\Pdo\DbLayer;

final readonly class RecoveryCodeRepository
{
    private const int CODE_COUNT = 10;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @return list<string> */
    public function regenerate(int $userId, ?int $now = null): array
    {
        $now ??= time();
        $this->dbLayer
            ->delete(WebAuthnSchema::RECOVERY_CODE_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;

        $codes = [];
        for ($index = 0; $index < self::CODE_COUNT; ++$index) {
            $plain = bin2hex(random_bytes(10));
            $this->dbLayer
                ->insert(WebAuthnSchema::RECOVERY_CODE_TABLE)
                ->setValue('code_hash', ':code_hash')->setParameter('code_hash', $this->hash($plain))
                ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
                ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
                ->setValue('used_at', 'NULL')
                ->execute()
            ;
            $codes[] = implode('-', str_split($plain, 4));
        }

        return $codes;
    }

    public function consume(string $login, string $candidate, ?int $now = null): ?int
    {
        $normalized = $this->normalize($candidate);
        if ($normalized === null) {
            return null;
        }

        $result = $this->dbLayer
            ->select('r.user_id')
            ->from(WebAuthnSchema::RECOVERY_CODE_TABLE . ' AS r')
            ->innerJoin('users AS u', 'u.id = r.user_id')
            ->where('r.code_hash = :code_hash')->setParameter('code_hash', $this->hash($normalized))
            ->andWhere('r.used_at IS NULL')
            ->andWhere('u.login = :login')->setParameter('login', $login)
            ->execute()
        ;
        $userId = $result->result();
        $result->freeResult();
        if ($userId === false) {
            return null;
        }

        $update = $this->dbLayer
            ->update(WebAuthnSchema::RECOVERY_CODE_TABLE)
            ->set('used_at', ':used_at')->setParameter('used_at', $now ?? time())
            ->where('code_hash = :code_hash')->setParameter('code_hash', $this->hash($normalized))
            ->andWhere('user_id = :user_id')->setParameter('user_id', (int)$userId)
            ->andWhere('used_at IS NULL')
            ->execute()
        ;

        return $update->affectedRows() === 1 ? (int)$userId : null;
    }

    /** @return array{available:int,created_at:?int} */
    public function status(int $userId): array
    {
        $result = $this->dbLayer
            ->select('COUNT(*), MAX(created_at)')
            ->from(WebAuthnSchema::RECOVERY_CODE_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->andWhere('used_at IS NULL')
            ->execute()
        ;
        $row = $result->fetchRow();
        $result->freeResult();

        return [
            'available'  => $row === false ? 0 : (int)$row[0],
            'created_at' => $row === false || $row[1] === null ? null : (int)$row[1],
        ];
    }

    private function hash(string $normalizedCode): string
    {
        return hash('sha256', "webauthn-recovery-code\0" . $normalizedCode);
    }

    private function normalize(string $candidate): ?string
    {
        $normalized = strtolower(str_replace(['-', ' '], '', trim($candidate)));

        return preg_match('/^[a-f0-9]{20}$/D', $normalized) === 1 ? $normalized : null;
    }
}
