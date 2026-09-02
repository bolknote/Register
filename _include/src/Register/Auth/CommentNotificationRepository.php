<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Computes the user's relevant unread comments and records read state. */
final class CommentNotificationRepository implements StatefulServiceInterface
{
    private const string GLOBAL_VERSION_KEY = 'register_comment_notifications_global_v1';

    private const string USER_VERSION_PREFIX = 'register_comment_notifications_user_version_v1_';

    private const string SNAPSHOT_PREFIX = 'register_comment_notifications_snapshot_v1_';

    /** @var array<int, array{dependency: string, identity: string, rows: list<array{id: int, content_type: string, content_id: int}>}> */
    private array $localSnapshots = [];

    private ?string $globalVersion = null;

    /** @var array<int, string> */
    private array $userVersions = [];

    public function __construct(
        private readonly DbLayer             $dbLayer,
        private readonly PublicAuthRepository $authRepository,
        private readonly CacheInterface       $cache,
        private readonly ?PDO                 $pdo = null,
        private readonly bool                 $cacheDisabled = false,
    ) {
    }

    public function countUnread(AuthenticatedPublicUser $user): int
    {
        return \count($this->snapshot($user)['rows']);
    }

    public function firstUnread(AuthenticatedPublicUser $user): ?CommentNotification
    {
        $row = $this->snapshot($user)['rows'][0] ?? null;
        if (!\is_array($row)) {
            return null;
        }

        $contentType = ContentType::tryFrom($row['content_type']);
        if (!$contentType instanceof ContentType) {
            return null;
        }

        return new CommentNotification(
            $row['id'],
            new ContentId($contentType, $row['content_id']),
        );
    }

    public function markContentRead(AuthenticatedPublicUser $user, ContentId $contentId): void
    {
        $rows = $this->snapshot($user)['rows'];
        $now = time();
        $changed = false;
        foreach ($rows as $row) {
            if ($row['content_type'] !== $contentId->type->value
                || $row['content_id'] !== $contentId->value
            ) {
                continue;
            }

            $changed = $this->dbLayer
                ->insert(PublicAuthSchema::NOTIFICATION_READS_TABLE)
                ->values([
                    'user_id'    => ':user_id',
                    'comment_id' => ':comment_id',
                    'read_at'    => ':read_at',
                ])
                ->onConflictDoNothing('user_id', 'comment_id')
                ->execute([
                    'user_id'    => $user->id,
                    'comment_id' => $row['id'],
                    'read_at'    => $now,
                ])->affectedRows() > 0 || $changed;
        }

        if ($changed) {
            $this->invalidateUser($user->id);
        }
    }

    /** Invalidates every user's snapshot when a comment changes visibility or relevance. */
    public function invalidateAll(bool $deferUntilCommit = false): void
    {
        $this->invalidateVersion(
            self::GLOBAL_VERSION_KEY,
            'comment-notifications:global',
            function (): void {
                $this->globalVersion = null;
                $this->localSnapshots = [];
            },
            $deferUntilCommit,
        );
    }

    #[\Override]
    public function clearState(): void
    {
        $this->localSnapshots = [];
        $this->globalVersion = null;
        $this->userVersions = [];
    }

    /**
     * @return array{dependency: string, identity: string, rows: list<array{id: int, content_type: string, content_id: int}>}
     */
    private function snapshot(AuthenticatedPublicUser $user): array
    {
        $identity = hash('sha256', implode("\0", [
            (string)$user->id,
            mb_strtolower($user->email, 'UTF-8'),
            $user->canHideComments ? '1' : '0',
            $user->canEditComments ? '1' : '0',
        ]));
        $dependency = $this->globalVersion() . ':' . $this->userVersion($user->id);
        $local = $this->localSnapshots[$user->id] ?? null;
        if ($local !== null
            && $local['dependency'] === $dependency
            && $local['identity'] === $identity
        ) {
            return $local;
        }

        $build = function (ItemInterface $item) use ($user, $dependency, $identity): array {
            $item->expiresAfter(null);

            return $this->buildSnapshot($user, $dependency, $identity);
        };

        if ($this->cacheDisabled) {
            $snapshot = $this->buildSnapshot($user, $dependency, $identity);
        } else {
            $cacheKey = self::SNAPSHOT_PREFIX . $user->id;
            $snapshot = $this->normalizeSnapshot($this->cached($cacheKey, $build), $dependency, $identity);
            if ($snapshot === null) {
                $this->cache->delete($cacheKey);
                $snapshot = $this->normalizeSnapshot($this->cached($cacheKey, $build), $dependency, $identity);
            }
        }

        if ($snapshot === null) {
            throw new \UnexpectedValueException('The comment notification cache returned an invalid snapshot.');
        }

        return $this->localSnapshots[$user->id] = $snapshot;
    }

    /**
     * @return array{dependency: string, identity: string, rows: list<array{id: int, content_type: string, content_id: int}>}
     */
    private function buildSnapshot(
        AuthenticatedPublicUser $user,
        string                  $dependency,
        string                  $identity,
    ): array {
        $this->authRepository->ensureNotificationBaseline($user->id);

        $rows = [];
        foreach ($this->unreadRows($user)->fetchAssocAll() as $row) {
            $contentType = ContentType::tryFrom((string)($row['content_type'] ?? ''));
            if (!$contentType instanceof ContentType) {
                continue;
            }

            $rows[] = [
                'id'           => (int)$row['id'],
                'content_type' => $contentType->value,
                'content_id'   => (int)$row['content_id'],
            ];
        }

        return [
            'dependency' => $dependency,
            'identity'   => $identity,
            'rows'       => $rows,
        ];
    }

    private function unreadRows(AuthenticatedPublicUser $user): \Register\Core\Pdo\QueryResult
    {
        $prefix = $this->dbLayer->getPrefix();
        $sql = 'SELECT c.id, c.content_type, c.content_id'
            . ' FROM ' . $prefix . 'comments AS c'
            . ' INNER JOIN ' . $prefix . 'comment_notification_users AS nu ON nu.user_id = :user_id'
            . ' INNER JOIN ' . $prefix . 'content AS content_item'
            . ' ON content_item.id = c.content_id AND content_item.content_type = c.content_type'
            . ' LEFT JOIN ' . $prefix . 'comments AS parent_comment ON parent_comment.id = c.parent_id'
            . ' LEFT JOIN ' . $prefix . 'comment_notification_reads AS nr'
            . ' ON nr.user_id = :read_user_id AND nr.comment_id = c.id'
            . ' WHERE c.deleted = 0'
            . ' AND (c.user_id IS NULL OR c.user_id <> :own_user_id)'
            . " AND (c.email = '' OR LOWER(c.email) <> LOWER(:own_email))"
            . ' AND ('
            . " (c.shown = 0 AND c.sent = 0 AND :include_pending = '1')"
            . ' OR (c.id > nu.initial_comment_id'
            . ' AND nr.comment_id IS NULL'
            . ' AND c.shown = 1 AND ('
            . ' content_item.author_id = :author_user_id'
            . ' OR parent_comment.user_id = :parent_user_id'
            . " OR (parent_comment.email <> '' AND LOWER(parent_comment.email) = LOWER(:parent_email))"
            . ' OR EXISTS (SELECT 1 FROM ' . $prefix . 'comments AS own_comment'
            . ' WHERE own_comment.content_type = c.content_type'
            . ' AND own_comment.content_id = c.content_id'
            . ' AND own_comment.id < c.id'
            . ' AND own_comment.shown = 1'
            . ' AND own_comment.deleted = 0'
            . ' AND own_comment.subscribed = 1'
            . ' AND (own_comment.user_id = :participant_user_id'
            . " OR (own_comment.email <> '' AND LOWER(own_comment.email) = LOWER(:participant_email))))))"
            . ')'
            . ' ORDER BY CASE WHEN c.shown = 0 AND c.sent = 0 THEN 0 ELSE 1 END, c.id ASC';

        return $this->dbLayer->query($sql, [
            'user_id'             => $user->id,
            'read_user_id'        => $user->id,
            'own_user_id'         => $user->id,
            'own_email'           => $user->email,
            'include_pending'     => $user->canHideComments || $user->canEditComments ? 1 : 0,
            'author_user_id'      => $user->id,
            'parent_user_id'      => $user->id,
            'parent_email'        => $user->email,
            'participant_user_id' => $user->id,
            'participant_email'   => $user->email,
        ]);
    }

    private function globalVersion(): string
    {
        if ($this->globalVersion !== null) {
            return $this->globalVersion;
        }

        return $this->globalVersion = $this->version(self::GLOBAL_VERSION_KEY);
    }

    private function userVersion(int $userId): string
    {
        return $this->userVersions[$userId] ??= $this->version(self::USER_VERSION_PREFIX . $userId);
    }

    private function version(string $key): string
    {
        if ($this->cacheDisabled) {
            return bin2hex(random_bytes(8));
        }

        $version = $this->cached($key, static function (ItemInterface $item): string {
            $item->expiresAfter(null);

            return bin2hex(random_bytes(8));
        });
        if (!\is_string($version) || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1) {
            throw new \UnexpectedValueException('A comment notification cache version is invalid.');
        }

        return $version;
    }

    private function invalidateUser(int $userId): void
    {
        $this->invalidateVersion(
            self::USER_VERSION_PREFIX . $userId,
            'comment-notifications:user:' . $userId,
            function () use ($userId): void {
                unset($this->userVersions[$userId], $this->localSnapshots[$userId]);
            },
            false,
        );
    }

    /** @param \Closure(): void $clearLocal */
    private function invalidateVersion(
        string   $key,
        string   $callbackKey,
        \Closure $clearLocal,
        bool     $deferUntilCommit,
    ): void {
        if ($this->cacheDisabled) {
            $clearLocal();
            return;
        }

        $clear = function () use ($key, $clearLocal): void {
            $clearLocal();
            $this->cache->delete($key);
        };
        if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
            if ($deferUntilCommit) {
                $this->pdo->afterCommitOnce($callbackKey, $clear);
                return;
            }

            if ($this->pdo->afterCommitOnce($callbackKey, $clear)) {
                $this->pdo->afterRollbackOnce($callbackKey, $clear);
            }
        }

        $clear();
    }

    /** @param callable(ItemInterface): mixed $factory */
    private function cached(string $key, callable $factory): mixed
    {
        // Cache storage is an external boundary; validate its payload instead of trusting the
        // generic return type inferred from the factory.
        return $this->cache->get(
            $key,
            static fn(ItemInterface $item, bool $save): mixed => match ($save) {
                true, false => $factory($item),
            },
            0.0,
        );
    }

    /**
     * @return array{dependency: string, identity: string, rows: list<array{id: int, content_type: string, content_id: int}>}|null
     */
    private function normalizeSnapshot(mixed $snapshot, string $dependency, string $identity): ?array
    {
        if (!\is_array($snapshot)
            || ($snapshot['dependency'] ?? null) !== $dependency
            || ($snapshot['identity'] ?? null) !== $identity
            || !\is_array($snapshot['rows'] ?? null)
            || !array_is_list($snapshot['rows'])
        ) {
            return null;
        }

        $rows = [];
        foreach ($snapshot['rows'] as $row) {
            if (!\is_array($row)
                || !\is_int($row['id'] ?? null)
                || !\is_string($row['content_type'] ?? null)
                || !\is_int($row['content_id'] ?? null)
            ) {
                return null;
            }

            $rows[] = [
                'id'           => $row['id'],
                'content_type' => $row['content_type'],
                'content_id'   => $row['content_id'],
            ];
        }

        return [
            'dependency' => $dependency,
            'identity'   => $identity,
            'rows'       => $rows,
        ];
    }
}
