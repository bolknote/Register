<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Comment\CommentSchema;
use Register\Core\Model\PasswordHasher;
use Register\Core\Pdo\DbLayer;

/** Database operations for public identities and short-lived authentication challenges. */
final readonly class PublicAuthRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * Resolves only the exact provider identity. Equal email addresses are deliberately not used
     * to merge an external account into a privileged local account.
     */
    public function findOrCreateIdentity(
        string $provider,
        string $subject,
        string $email,
        string $displayName,
        string $avatarUrl = '',
    ): int {
        $provider    = $this->provider($provider);
        $subject     = mb_substr(trim($subject), 0, 191);
        $email       = mb_strtolower(mb_substr(trim($email), 0, 80));
        $displayName = mb_substr(trim($displayName), 0, 80);
        $avatarUrl   = mb_substr(trim($avatarUrl), 0, 1024);
        if ($subject === '') {
            throw new \InvalidArgumentException('An external identity subject cannot be empty.');
        }

        $row = $this->identity($provider, $subject);
        if ($row !== null) {
            $userId = (int)$row['user_id'];
            $this->updateIdentity($userId, $provider, $subject, $email, $displayName, $avatarUrl);
            $this->ensureNotificationBaseline($userId);

            return $userId;
        }

        $login = $this->newExternalLogin($provider);
        $this->dbLayer
            ->insert('users')
            ->values([
                'login'           => ':login',
                'password'        => ':password',
                'email'           => ':email',
                'name'            => ':name',
                'view'            => '0',
                'view_hidden'     => '0',
                'hide_comments'   => '0',
                'edit_comments'   => '0',
                'create_articles' => '0',
                'edit_site'       => '0',
                'edit_users'      => '0',
            ])
            ->execute([
                'login'    => $login,
                'password' => PasswordHasher::hash(bin2hex(random_bytes(32))),
                'email'    => $email,
                'name'     => $displayName,
            ])
        ;
        $userId = (int)$this->dbLayer->insertId();

        $now = time();
        $this->dbLayer
            ->insert(PublicAuthSchema::IDENTITIES_TABLE)
            ->values([
                'user_id'      => ':user_id',
                'provider'     => ':provider',
                'subject'      => ':subject',
                'email'        => ':email',
                'display_name' => ':display_name',
                'avatar_url'   => ':avatar_url',
                'created_at'   => ':created_at',
                'updated_at'   => ':updated_at',
            ])
            ->execute([
                'user_id'      => $userId,
                'provider'     => $provider,
                'subject'      => $subject,
                'email'        => $email,
                'display_name' => $displayName,
                'avatar_url'   => $avatarUrl,
                'created_at'   => $now,
                'updated_at'   => $now,
            ])
        ;
        $this->ensureNotificationBaseline($userId);

        return $userId;
    }

    public function ensureNotificationBaseline(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $lastCommentId = (int)$this->dbLayer
            ->select('COALESCE(MAX(id), 0)')
            ->from(CommentSchema::TABLE_NAME)
            ->execute()
            ->result()
        ;
        $this->dbLayer
            ->insert(PublicAuthSchema::NOTIFICATION_USERS_TABLE)
            ->values([
                'user_id'            => ':user_id',
                'initial_comment_id' => ':initial_comment_id',
                'created_at'         => ':created_at',
            ])
            ->onConflictDoNothing('user_id')
            ->execute([
                'user_id'            => $userId,
                'initial_comment_id' => $lastCommentId,
                'created_at'         => time(),
            ])
        ;
    }

    public function findUserIdByLogin(string $login): ?int
    {
        $value = $this->dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->result()
        ;

        return is_numeric($value) ? (int)$value : null;
    }

    public function storeFlow(
        string $state,
        string $provider,
        string $codeVerifier,
        string $deviceId,
        string $returnPath,
        int $lifetime = 600,
    ): void {
        $this->cleanupExpired();
        $now = time();
        $this->dbLayer
            ->insert(PublicAuthSchema::FLOWS_TABLE)
            ->values([
                'state_hash'    => ':state_hash',
                'provider'      => ':provider',
                'code_verifier' => ':code_verifier',
                'device_id'     => ':device_id',
                'return_path'   => ':return_path',
                'created_at'    => ':created_at',
                'expires_at'    => ':expires_at',
            ])
            ->execute([
                'state_hash'    => self::tokenHash($state),
                'provider'      => $this->provider($provider),
                'code_verifier' => mb_substr($codeVerifier, 0, 128),
                'device_id'     => mb_substr($deviceId, 0, 80),
                'return_path'   => mb_substr($returnPath, 0, 1024),
                'created_at'    => $now,
                'expires_at'    => $now + max(60, $lifetime),
            ])
        ;
    }

    /** @return array<string, mixed>|null */
    public function consumeFlow(string $state): ?array
    {
        $stateHash = self::tokenHash($state);
        $row = $this->dbLayer
            ->select('*')
            ->from(PublicAuthSchema::FLOWS_TABLE)
            ->where('state_hash = :state_hash')->setParameter('state_hash', $stateHash)
            ->andWhere('expires_at >= :now')->setParameter('now', time())
            ->execute()
            ->fetchAssoc()
        ;
        $this->dbLayer
            ->delete(PublicAuthSchema::FLOWS_TABLE)
            ->where('state_hash = :state_hash')->setParameter('state_hash', $stateHash)
            ->execute()
        ;

        return $row === false ? null : $row;
    }

    /**
     * @param array{content_type?: string, content_id?: int|null, parent_id?: int|null, text?: string, subscribed?: bool, moderation_required?: bool, ip?: string}|null $pendingComment
     */
    public function storeMagicLink(
        string $token,
        string $email,
        string $displayName,
        string $returnPath,
        ?array $pendingComment = null,
        int $lifetime = 900,
        ?string $visitorId = null,
    ): void {
        $this->cleanupExpired();
        $now = time();
        $this->dbLayer
            ->insert(PublicAuthSchema::MAGIC_LINKS_TABLE)
            ->values([
                'token_hash'   => ':token_hash',
                'email'        => ':email',
                'display_name' => ':display_name',
                'return_path'  => ':return_path',
                'content_type' => ':content_type',
                'content_id'   => ':content_id',
                'parent_id'    => ':parent_id',
                'comment_text' => ':comment_text',
                'visitor_id'   => ':visitor_id',
                'subscribed'   => ':subscribed',
                'moderation_required' => ':moderation_required',
                'ip'           => ':ip',
                'created_at'   => ':created_at',
                'expires_at'   => ':expires_at',
                'used_at'      => 'NULL',
            ])
            ->execute([
                'token_hash'   => self::tokenHash($token),
                'email'        => mb_strtolower(mb_substr(trim($email), 0, 80)),
                'display_name' => mb_substr(trim($displayName), 0, 80),
                'return_path'  => mb_substr($returnPath, 0, 1024),
                'content_type' => mb_substr($pendingComment['content_type'] ?? '', 0, 8),
                'content_id'   => $pendingComment['content_id'] ?? null,
                'parent_id'    => $pendingComment['parent_id'] ?? null,
                'comment_text' => $pendingComment['text'] ?? null,
                'visitor_id'   => $visitorId,
                'subscribed'   => (int)($pendingComment['subscribed'] ?? false),
                'moderation_required' => (int)($pendingComment['moderation_required'] ?? false),
                'ip'           => mb_substr($pendingComment['ip'] ?? '', 0, 39),
                'created_at'   => $now,
                'expires_at'   => $now + max(60, $lifetime),
            ])
        ;
    }

    /** @return array<string, mixed>|null */
    public function consumeMagicLink(string $token): ?array
    {
        $tokenHash = self::tokenHash($token);
        $now = time();
        $row = $this->dbLayer
            ->select('*')
            ->from(PublicAuthSchema::MAGIC_LINKS_TABLE)
            ->where('token_hash = :token_hash')->setParameter('token_hash', $tokenHash)
            ->andWhere('used_at IS NULL')
            ->andWhere('expires_at >= :now')->setParameter('now', $now)
            ->execute()
            ->fetchAssoc()
        ;
        if ($row === false) {
            return null;
        }

        $updated = $this->dbLayer
            ->update(PublicAuthSchema::MAGIC_LINKS_TABLE)
            ->set('used_at', ':used_at')->setParameter('used_at', $now)
            ->where('token_hash = :token_hash')->setParameter('token_hash', $tokenHash)
            ->andWhere('used_at IS NULL')
            ->execute()
            ->affectedRows()
        ;

        return $updated === 1 ? $row : null;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed>|null */
    private function identity(string $provider, string $subject): ?array
    {
        $row = $this->dbLayer
            ->select('*')
            ->from(PublicAuthSchema::IDENTITIES_TABLE)
            ->where('provider = :provider')->setParameter('provider', $provider)
            ->andWhere('subject = :subject')->setParameter('subject', $subject)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : $row;
    }

    private function updateIdentity(
        int $userId,
        string $provider,
        string $subject,
        string $email,
        string $displayName,
        string $avatarUrl,
    ): void {
        $this->dbLayer
            ->update(PublicAuthSchema::IDENTITIES_TABLE)
            ->set('email', ':email')->setParameter('email', $email)
            ->set('display_name', ':display_name')->setParameter('display_name', $displayName)
            ->set('avatar_url', ':avatar_url')->setParameter('avatar_url', $avatarUrl)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', time())
            ->where('provider = :provider')->setParameter('provider', $provider)
            ->andWhere('subject = :subject')->setParameter('subject', $subject)
            ->execute()
        ;
        $this->dbLayer
            ->update('users')
            ->set('email', ':email')->setParameter('email', $email)
            ->set('name', ':name')->setParameter('name', $displayName)
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
        ;
    }

    private function cleanupExpired(): void
    {
        $now = time();
        $this->dbLayer
            ->delete(PublicAuthSchema::FLOWS_TABLE)
            ->where('expires_at < :now')->setParameter('now', $now)
            ->execute()
        ;
        $this->dbLayer
            ->delete(PublicAuthSchema::MAGIC_LINKS_TABLE)
            ->where('expires_at < :expired')->setParameter('expired', $now - 86400)
            ->execute()
        ;
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z][a-z0-9_]{1,23}$/D', $provider) !== 1) {
            throw new \InvalidArgumentException('Invalid public authentication provider.');
        }

        return $provider;
    }

    private function newExternalLogin(string $provider): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $login = 'external_' . $provider . '_' . bin2hex(random_bytes(8));
            $exists = (int)$this->dbLayer
                ->select('COUNT(*)')
                ->from('users')
                ->where('login = :login')->setParameter('login', $login)
                ->execute()
                ->result()
            ;
            if ($exists === 0) {
                return $login;
            }
        }

        throw new \RuntimeException('Unable to allocate an external account identifier.');
    }
}
