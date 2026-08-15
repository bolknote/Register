<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

use S2\Cms\Pdo\DbLayer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\CredentialRecord;

final readonly class WebAuthnCredentialRepository
{
    public function __construct(
        private DbLayer             $dbLayer,
        private SerializerInterface $serializer,
    ) {
    }

    public function userHandle(int $userId): string
    {
        $result = $this->dbLayer
            ->select('user_handle')
            ->from(WebAuthnSchema::USER_HANDLE_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;
        $handle = $result->result();
        $result->freeResult();
        if (\is_string($handle) && $handle !== '') {
            return $this->decode($handle);
        }

        $candidate = random_bytes(32);
        $this->dbLayer
            ->insert(WebAuthnSchema::USER_HANDLE_TABLE)
            ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
            ->setValue('user_handle', ':user_handle')->setParameter('user_handle', $this->encode($candidate))
            ->setValue('created_at', ':created_at')->setParameter('created_at', time())
            ->onConflictDoNothing('user_id')
            ->execute()
        ;

        $result = $this->dbLayer
            ->select('user_handle')
            ->from(WebAuthnSchema::USER_HANDLE_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;
        $handle = $result->result();
        $result->freeResult();
        if (!\is_string($handle) || $handle === '') {
            throw new \RuntimeException('Unable to persist a WebAuthn user handle.');
        }

        return $this->decode($handle);
    }

    public function userIdByHandle(string $userHandle): ?int
    {
        $result = $this->dbLayer
            ->select('user_id')
            ->from(WebAuthnSchema::USER_HANDLE_TABLE)
            ->where('user_handle = :user_handle')->setParameter('user_handle', $this->encode($userHandle))
            ->execute()
        ;
        $userId = $result->result();
        $result->freeResult();

        return $userId === false ? null : (int)$userId;
    }

    /** @return list<WebAuthnCredential> */
    public function forUser(int $userId): array
    {
        $result = $this->dbLayer
            ->select('credential_hash, user_id, record, name, created_at, last_used_at')
            ->from(WebAuthnSchema::CREDENTIAL_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->orderBy('created_at DESC')
            ->execute()
        ;
        $credentials = [];
        foreach ($result->fetchAssocAll() as $row) {
            $credentials[] = $this->hydrate($row);
        }

        $result->freeResult();

        return $credentials;
    }

    public function find(string $credentialId): ?WebAuthnCredential
    {
        $result = $this->dbLayer
            ->select('credential_hash, user_id, record, name, created_at, last_used_at')
            ->from(WebAuthnSchema::CREDENTIAL_TABLE)
            ->where('credential_hash = :credential_hash')
            ->setParameter('credential_hash', self::credentialHash($credentialId))
            ->execute()
        ;
        $row = $result->fetchAssoc();
        $result->freeResult();

        return $row === false ? null : $this->hydrate($row);
    }

    public function add(int $userId, string $name, CredentialRecord $record, ?int $now = null): void
    {
        $this->dbLayer
            ->insert(WebAuthnSchema::CREDENTIAL_TABLE)
            ->setValue('credential_hash', ':credential_hash')
            ->setParameter('credential_hash', self::credentialHash($record->publicKeyCredentialId))
            ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
            ->setValue('record', ':record')->setParameter('record', $this->serialize($record))
            ->setValue('name', ':name')->setParameter('name', $name)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now ?? time())
            ->setValue('last_used_at', 'NULL')
            ->execute()
        ;
    }

    public function updateAfterUse(WebAuthnCredential $credential, CredentialRecord $record, ?int $now = null): void
    {
        $this->dbLayer
            ->update(WebAuthnSchema::CREDENTIAL_TABLE)
            ->set('record', ':record')->setParameter('record', $this->serialize($record))
            ->set('last_used_at', ':last_used_at')->setParameter('last_used_at', $now ?? time())
            ->where('credential_hash = :credential_hash')->setParameter('credential_hash', $credential->hash)
            ->execute()
        ;
    }

    public function delete(int $userId, string $credentialHash): bool
    {
        $result = $this->dbLayer
            ->delete(WebAuthnSchema::CREDENTIAL_TABLE)
            ->where('credential_hash = :credential_hash')->setParameter('credential_hash', $credentialHash)
            ->andWhere('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;

        return $result->affectedRows() === 1;
    }

    public static function credentialHash(string $credentialId): string
    {
        return hash('sha256', "webauthn-credential\0" . $credentialId);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): WebAuthnCredential
    {
        $record = $this->serializer->deserialize((string)$row['record'], CredentialRecord::class, 'json');

        return new WebAuthnCredential(
            (string)$row['credential_hash'],
            (int)$row['user_id'],
            (string)$row['name'],
            (int)$row['created_at'],
            $row['last_used_at'] === null ? null : (int)$row['last_used_at'],
            $record,
        );
    }

    private function serialize(CredentialRecord $record): string
    {
        return $this->serializer->serialize($record, 'json');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Invalid stored WebAuthn user handle.');
        }

        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid stored WebAuthn user handle.');
        }

        return $decoded;
    }
}
