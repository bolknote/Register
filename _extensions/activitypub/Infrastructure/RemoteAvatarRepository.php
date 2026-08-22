<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\QueryBuilder\UpdateBuilder;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Media\InspectedRemoteAvatar;

/** Durable truth for privacy-preserving remote avatar mirroring. */
final readonly class RemoteAvatarRepository
{
    private const int FETCH_WINDOW_SECONDS = 7 * 24 * 60 * 60;

    private const int REFRESH_SECONDS = 24 * 60 * 60;

    private const int STALE_SECONDS = 7 * 24 * 60 * 60;

    private const int CLAIM_TIMEOUT_SECONDS = 15 * 60;

    public function __construct(private DbLayer $dbLayer, private PublicIdGenerator $publicIdGenerator)
    {
    }

    /** Returns true when the background queue should be woken. */
    public function synchronizeSource(int $remoteActorId, ?string $sourceUrl, int $now): bool
    {
        if ($remoteActorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('A remote avatar source transition is invalid.');
        }

        if ($sourceUrl !== null) {
            $this->validateHttpsUrl($sourceUrl);
        }

        $row = $this->findRowByActorId($remoteActorId);
        if ($sourceUrl === null) {
            if ($row === null) {
                return false;
            }

            $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
                ->set('source_url_hash', ':source_url_hash')->setParameter('source_url_hash', hash('sha256', ''))
                ->set('source_url', ':source_url')->setParameter('source_url', '')
                ->set('request_url', ':request_url')->setParameter('request_url', '')
                ->set('state', ':state')->setParameter('state', 'retired')
                ->set('claim_token', ':claim_token')->setParameter('claim_token', null)
                ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
                ->set('refresh_at', '0')
                ->set('serve_until', '0')
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->where('id = :id')->setParameter('id', (int)$row['id'])
                ->execute()
            ;

            return false;
        }

        $sourceHash = hash('sha256', $sourceUrl);
        if ($row === null) {
            $this->dbLayer->insert(ActivityPubSchema::REMOTE_MEDIA_TABLE)
                ->values([
                    'public_id'             => ':public_id',
                    'remote_actor_id'       => ':remote_actor_id',
                    'source_url_hash'       => ':source_url_hash',
                    'source_url'            => ':source_url',
                    'published_source_hash' => ':published_source_hash',
                    'request_url'           => ':request_url',
                    'redirect_chain_json'   => ':redirect_chain_json',
                    'state'                 => ':state',
                    'available_at'          => ':available_at',
                    'give_up_at'            => ':give_up_at',
                    'created_at'            => ':created_at',
                    'updated_at'            => ':updated_at',
                ])
                ->onConflictDoNothing('remote_actor_id')
                ->execute([
                    'public_id'             => $this->publicIdGenerator->generate(),
                    'remote_actor_id'       => $remoteActorId,
                    'source_url_hash'       => $sourceHash,
                    'source_url'            => $sourceUrl,
                    'published_source_hash' => '',
                    'request_url'           => $sourceUrl,
                    'redirect_chain_json'   => '[]',
                    'state'                 => 'pending',
                    'available_at'          => $now,
                    'give_up_at'            => $now + self::FETCH_WINDOW_SECONDS,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ])
            ;
            $row = $this->findRowByActorId($remoteActorId);
            if ($row === null) {
                throw new \RuntimeException('A remote avatar cache row could not be created.');
            }

            if (hash_equals((string)$row['source_url_hash'], $sourceHash)
                && hash_equals((string)$row['source_url'], $sourceUrl)
            ) {
                return true;
            }
        }

        $sameSource = hash_equals((string)$row['source_url_hash'], $sourceHash)
            && hash_equals((string)$row['source_url'], $sourceUrl);
        $state = (string)$row['state'];
        if ($sameSource && \in_array($state, ['pending', 'processing'], true)) {
            return true;
        }

        if ($sameSource
            && $state === 'ready'
            && (int)$row['refresh_at'] > $now
            && (int)$row['serve_until'] > $now
        ) {
            return false;
        }

        if ($sameSource && $state === 'failed' && (int)$row['available_at'] > $now) {
            return false;
        }

        $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('source_url_hash', ':source_url_hash')->setParameter('source_url_hash', $sourceHash)
            ->set('source_url', ':source_url')->setParameter('source_url', $sourceUrl)
            ->set('request_url', ':request_url')->setParameter('request_url', $sourceUrl)
            ->set('redirect_count', '0')
            ->set('redirect_chain_json', ':redirect_chain')->setParameter('redirect_chain', '[]')
            ->set('state', ':state')->setParameter('state', 'pending')
            ->set('attempt_count', '0')
            ->set('available_at', ':available_at')->setParameter('available_at', $now)
            ->set('give_up_at', ':give_up_at')->setParameter('give_up_at', $now + self::FETCH_WINDOW_SECONDS)
            ->set('claim_token', ':claim_token')->setParameter('claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('last_http_status', ':last_http_status')->setParameter('last_http_status', null)
            ->set('error_code', ':error_code')->setParameter('error_code', '')
            ->set('last_error', ':last_error')->setParameter('last_error', '')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', (int)$row['id'])
            ->execute()
        ;

        return true;
    }

    public function recoverStaleClaims(int $now): int
    {
        return $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('state', ':pending')->setParameter('pending', 'pending')
            ->set('available_at', ':available_at')->setParameter('available_at', $now)
            ->set('claim_token', ':claim_token')->setParameter('claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state = :processing')->setParameter('processing', 'processing')
            ->andWhere('claimed_at IS NOT NULL')
            ->andWhere('claimed_at <= :stale')->setParameter('stale', max(0, $now - self::CLAIM_TIMEOUT_SECONDS))
            ->execute()
            ->affectedRows()
        ;
    }

    public function claimNext(int $now): ?ClaimedRemoteAvatar
    {
        $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('state', ':failed')->setParameter('failed', 'failed')
            ->set('available_at', ':retry_at')->setParameter('retry_at', $now + self::REFRESH_SECONDS)
            ->set('error_code', ':error_code')->setParameter('error_code', 'fetch_window_expired')
            ->set('last_error', ':last_error')->setParameter('last_error', 'The avatar fetch window expired.')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state = :pending')->setParameter('pending', 'pending')
            ->andWhere('give_up_at <= :now')->setParameter('now', $now)
            ->execute()
        ;

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('state = :state')->setParameter('state', 'pending')
            ->andWhere('available_at <= :now')->setParameter('now', $now)
            ->andWhere('give_up_at > :now')
            ->orderBy('available_at, id')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        $claimToken = bin2hex(random_bytes(16));
        $updated = $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('state', ':processing')->setParameter('processing', 'processing')
            ->set('attempt_count', 'attempt_count + 1')
            ->set('claim_token', ':claim_token')->setParameter('claim_token', $claimToken)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', (int)$row['id'])
            ->andWhere('state = :pending')->setParameter('pending', 'pending')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            return null;
        }

        $claimed = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('id = :id')->setParameter('id', (int)$row['id'])
            ->andWhere('claim_token = :claim_token')->setParameter('claim_token', $claimToken)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($claimed) ? $this->hydrateClaim($claimed) : null;
    }

    public function markRedirected(ClaimedRemoteAvatar $avatar, string $targetUrl, int $httpStatus, int $now): void
    {
        $this->validateHttpsUrl($targetUrl);
        if (hash_equals($avatar->sourceUrl, $targetUrl)
            || hash_equals($avatar->requestUrl, $targetUrl)
            || \in_array($targetUrl, $avatar->redirectChain, true)
        ) {
            throw new \DomainException('A remote avatar redirect loop was detected.');
        }

        $chain = [...$avatar->redirectChain, $avatar->requestUrl];
        $this->updateClaim($avatar)
            ->set('request_url', ':request_url')->setParameter('request_url', $targetUrl)
            ->set('redirect_count', ':redirect_count')->setParameter('redirect_count', $avatar->redirectCount + 1)
            ->set('redirect_chain_json', ':redirect_chain')->setParameter(
                'redirect_chain',
                json_encode($chain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            )
            ->set('state', ':state')->setParameter('state', 'pending')
            ->set('available_at', ':available_at')->setParameter('available_at', $now + 1)
            ->set('claim_token', ':new_claim_token')->setParameter('new_claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('last_http_status', ':last_http_status')->setParameter('last_http_status', $httpStatus)
            ->set('error_code', ':error_code')->setParameter('error_code', 'redirect')
            ->set('last_error', ':last_error')->setParameter('last_error', 'A validated redirect is queued as a separate HTTP hop.')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }

    public function markNotModified(
        ClaimedRemoteAvatar $avatar,
        ?string             $etag,
        ?string             $lastModified,
        int                 $now,
    ): bool {
        if ($avatar->storageKey === '' || !hash_equals($avatar->sourceUrlHash, $avatar->publishedSourceHash)) {
            return false;
        }

        return $this->updateClaim($avatar)
            ->set('state', ':state')->setParameter('state', 'ready')
            ->set('request_url', ':request_url')->setParameter('request_url', $avatar->sourceUrl)
            ->set('redirect_count', '0')
            ->set('redirect_chain_json', ':redirect_chain')->setParameter('redirect_chain', '[]')
            ->set('claim_token', ':new_claim_token')->setParameter('new_claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('etag', ':etag')->setParameter('etag', $etag ?? $avatar->etag ?? '')
            ->set('last_modified', ':last_modified')->setParameter(
                'last_modified',
                $lastModified ?? $avatar->lastModified ?? '',
            )
            ->set('fetched_at', ':fetched_at')->setParameter('fetched_at', $now)
            ->set('refresh_at', ':refresh_at')->setParameter('refresh_at', $now + self::REFRESH_SECONDS)
            ->set('serve_until', ':serve_until')->setParameter('serve_until', $now + self::STALE_SECONDS)
            ->set('last_http_status', '304')
            ->set('error_code', ':error_code')->setParameter('error_code', '')
            ->set('last_error', ':last_error')->setParameter('last_error', '')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function markReady(
        ClaimedRemoteAvatar  $avatar,
        InspectedRemoteAvatar $image,
        string                $storageKey,
        ?string               $etag,
        ?string               $lastModified,
        int                   $httpStatus,
        int                   $now,
    ): RemoteAvatarPublishResult {
        $oldStorageKey = $avatar->storageKey === '' ? null : $avatar->storageKey;
        $updated = $this->updateClaim($avatar)
            ->set('published_source_hash', ':published_source_hash')->setParameter(
                'published_source_hash',
                $avatar->sourceUrlHash,
            )
            ->set('request_url', ':request_url')->setParameter('request_url', $avatar->sourceUrl)
            ->set('redirect_count', '0')
            ->set('redirect_chain_json', ':redirect_chain')->setParameter('redirect_chain', '[]')
            ->set('state', ':state')->setParameter('state', 'ready')
            ->set('claim_token', ':new_claim_token')->setParameter('new_claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('storage_key', ':storage_key')->setParameter('storage_key', $storageKey)
            ->set('content_type', ':content_type')->setParameter('content_type', $image->contentType)
            ->set('content_hash', ':content_hash')->setParameter('content_hash', $image->contentHash)
            ->set('byte_size', ':byte_size')->setParameter('byte_size', $image->byteSize)
            ->set('width', ':width')->setParameter('width', $image->width)
            ->set('height', ':height')->setParameter('height', $image->height)
            ->set('etag', ':etag')->setParameter('etag', $etag ?? '')
            ->set('last_modified', ':last_modified')->setParameter('last_modified', $lastModified ?? '')
            ->set('fetched_at', ':fetched_at')->setParameter('fetched_at', $now)
            ->set('refresh_at', ':refresh_at')->setParameter('refresh_at', $now + self::REFRESH_SECONDS)
            ->set('serve_until', ':serve_until')->setParameter('serve_until', $now + self::STALE_SECONDS)
            ->set('last_http_status', ':last_http_status')->setParameter('last_http_status', $httpStatus)
            ->set('error_code', ':error_code')->setParameter('error_code', '')
            ->set('last_error', ':last_error')->setParameter('last_error', '')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
            ->affectedRows() === 1
        ;

        return new RemoteAvatarPublishResult(
            $updated,
            $updated && $oldStorageKey !== $storageKey ? $oldStorageKey : null,
        );
    }

    public function markDelayed(
        ClaimedRemoteAvatar $avatar,
        int                 $availableAt,
        ?int                $httpStatus,
        string              $errorCode,
        string              $detail,
        int                 $now,
    ): void {
        $this->updateClaim($avatar)
            ->set('state', ':state')->setParameter('state', 'pending')
            ->set('available_at', ':available_at')->setParameter('available_at', $availableAt)
            ->set('claim_token', ':new_claim_token')->setParameter('new_claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('last_http_status', ':last_http_status')->setParameter('last_http_status', $httpStatus)
            ->set('error_code', ':error_code')->setParameter('error_code', $errorCode)
            ->set('last_error', ':last_error')->setParameter('last_error', mb_substr($detail, 0, 4_000))
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }

    public function markFailed(
        ClaimedRemoteAvatar $avatar,
        ?int                $httpStatus,
        string              $errorCode,
        string              $detail,
        int                 $retryAt,
        int                 $now,
    ): void {
        $this->updateClaim($avatar)
            ->set('state', ':state')->setParameter('state', 'failed')
            ->set('available_at', ':available_at')->setParameter('available_at', $retryAt)
            ->set('claim_token', ':new_claim_token')->setParameter('new_claim_token', null)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', null)
            ->set('last_http_status', ':last_http_status')->setParameter('last_http_status', $httpStatus)
            ->set('error_code', ':error_code')->setParameter('error_code', $errorCode)
            ->set('last_error', ':last_error')->setParameter('last_error', mb_substr($detail, 0, 4_000))
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }

    public function earliestPendingAt(): ?int
    {
        $value = $this->dbLayer->select('MIN(available_at)')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('state = :state')->setParameter('state', 'pending')
            ->execute()
            ->result()
        ;

        return $value === null || $value === false ? null : (int)$value;
    }

    public function activateDue(int $now, int $limit = 100): int
    {
        if ($now < 1 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('A remote avatar refresh batch is invalid.');
        }

        $ids = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('(state = :ready AND refresh_at <= :now) OR (state = :failed AND available_at <= :now)')
            ->setParameter('ready', 'ready')
            ->setParameter('failed', 'failed')
            ->setParameter('now', $now)
            ->orderBy('refresh_at, available_at, id')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;
        $ids = array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        [$condition, $parameters] = $this->integerInCondition('id', $ids);
        $query = $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('request_url', 'source_url')
            ->set('redirect_count', '0')
            ->set('redirect_chain_json', ':redirect_chain')->setParameter('redirect_chain', '[]')
            ->set('state', ':pending')->setParameter('pending', 'pending')
            ->set('attempt_count', '0')
            ->set('available_at', ':available_at')->setParameter('available_at', $now)
            ->set('give_up_at', ':give_up_at')->setParameter('give_up_at', $now + self::FETCH_WINDOW_SECONDS)
            ->set('error_code', ':error_code')->setParameter('error_code', '')
            ->set('last_error', ':last_error')->setParameter('last_error', '')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where($condition)
        ;
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return $query->execute()->affectedRows();
    }

    /** @return list<string> Storage keys whose files may now be deleted. */
    public function detachExpiredAssets(int $now, int $limit = 100): array
    {
        $rows = $this->dbLayer->select('id', 'storage_key')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('storage_key <> :empty')->setParameter('empty', '')
            ->andWhere('serve_until <= :now')->setParameter('now', $now)
            ->andWhere('state <> :processing')->setParameter('processing', 'processing')
            ->orderBy('serve_until, id')
            ->limit($limit)
            ->execute()
            ->fetchAssocAll()
        ;
        $keys = [];
        $ids  = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || (int)($row['id'] ?? 0) < 1 || !\is_string($row['storage_key'] ?? null)) {
                continue;
            }

            $ids[]  = (int)$row['id'];
            $keys[] = $row['storage_key'];
        }

        if ($ids === []) {
            return [];
        }

        [$condition, $parameters] = $this->integerInCondition('id', $ids);
        $query = $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->set('storage_key', ':storage_key')->setParameter('storage_key', '')
            ->set('published_source_hash', ':published_source_hash')->setParameter('published_source_hash', '')
            ->set('content_type', ':content_type')->setParameter('content_type', '')
            ->set('content_hash', ':content_hash')->setParameter('content_hash', '')
            ->set('byte_size', '0')
            ->set('width', '0')
            ->set('height', '0')
            ->where($condition)
        ;
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        $query->execute();

        return $keys;
    }

    public function findPublicAsset(string $publicId, int $now): ?RemoteAvatarAsset
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1 || $now < 1) {
            return null;
        }

        $row = $this->dbLayer->select('media.*')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE . ' AS media')
            ->innerJoin(
                ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS actor',
                'actor.id = media.remote_actor_id',
            )
            ->where('media.public_id = :public_id')->setParameter('public_id', $publicId)
            ->andWhere('media.storage_key <> :empty')->setParameter('empty', '')
            ->andWhere('media.published_source_hash = media.source_url_hash')
            ->andWhere('media.serve_until > :now')->setParameter('now', $now)
            ->andWhere('actor.state IN (:active, :moved)')
            ->setParameter('active', 'active')
            ->setParameter('moved', 'moved')
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateAsset($row) : null;
    }

    /** @return array<string, mixed>|null */
    private function findRowByActorId(int $remoteActorId): ?array
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrateClaim(array $row): ClaimedRemoteAvatar
    {
        try {
            $chain = json_decode((string)$row['redirect_chain_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!\is_array($chain) || !array_is_list($chain)) {
                throw new \JsonException('Expected a remote avatar redirect list.');
            }

            $redirectChain = [];
            foreach ($chain as $url) {
                if (!\is_string($url)) {
                    throw new \JsonException('Expected a remote avatar redirect URL.');
                }

                $redirectChain[] = $url;
            }

            return new ClaimedRemoteAvatar(
                (int)$row['id'],
                (string)$row['public_id'],
                (int)$row['remote_actor_id'],
                (string)$row['source_url'],
                (string)$row['source_url_hash'],
                (string)$row['published_source_hash'],
                (string)$row['request_url'],
                (int)$row['redirect_count'],
                $redirectChain,
                (int)$row['attempt_count'],
                (int)$row['give_up_at'],
                (string)$row['claim_token'],
                (string)$row['storage_key'],
                (string)$row['content_hash'],
                (int)$row['byte_size'],
                $row['etag'] === null || $row['etag'] === '' ? null : (string)$row['etag'],
                $row['last_modified'] === null || $row['last_modified'] === ''
                    ? null
                    : (string)$row['last_modified'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored remote avatar claim is invalid.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateAsset(array $row): RemoteAvatarAsset
    {
        try {
            return new RemoteAvatarAsset(
                (string)$row['public_id'],
                (string)$row['storage_key'],
                (string)$row['content_type'],
                (string)$row['content_hash'],
                (int)$row['byte_size'],
                (int)$row['width'],
                (int)$row['height'],
                (int)$row['fetched_at'],
                (int)$row['serve_until'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored public remote avatar is invalid.', 0, $exception);
        }
    }

    private function updateClaim(ClaimedRemoteAvatar $avatar): UpdateBuilder
    {
        return $this->dbLayer->update(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('id = :id')->setParameter('id', $avatar->id)
            ->andWhere('state = :processing')->setParameter('processing', 'processing')
            ->andWhere('claim_token = :claim_token')->setParameter('claim_token', $avatar->claimToken)
            ->andWhere('source_url_hash = :source_url_hash')->setParameter('source_url_hash', $avatar->sourceUrlHash)
        ;
    }

    private function validateHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('A remote avatar URL must be bounded credential-free HTTPS.');
        }
    }

    /**
     * @param non-empty-list<int> $ids
     * @return array{string, array<string, int>}
     */
    private function integerInCondition(string $column, array $ids): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = 'remote_avatar_id_' . $index;
            $parameters[$name] = $id;
            $placeholders[] = ':' . $name;
        }

        return [$column . ' IN (' . implode(', ', $placeholders) . ')', $parameters];
    }
}
