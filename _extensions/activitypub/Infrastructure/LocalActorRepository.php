<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorKey;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\NewLocalActor;
use Register\Extension\activitypub\Security\EncryptedPrivateKey;

final readonly class LocalActorRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function findByPublicId(string $publicId): ?LocalActor
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            return null;
        }

        $row = $this->actorQuery()
            ->where('actor.public_id = :public_id')->setParameter('public_id', $publicId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    public function findById(int $id): ?LocalActor
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->actorQuery()
            ->where('actor.id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    public function findByHandle(string $handle): ?LocalActor
    {
        $row = $this->dbLayer->select('actor.*, current_handle.handle')
            ->from(ActivityPubSchema::ACTOR_TABLE . ' AS actor')
            ->innerJoin(
                ActivityPubSchema::ACTOR_HANDLE_TABLE . ' AS lookup_handle',
                'lookup_handle.actor_id = actor.id',
            )
            ->innerJoin(
                ActivityPubSchema::ACTOR_HANDLE_TABLE . ' AS current_handle',
                'current_handle.actor_id = actor.id AND current_handle.is_current = 1',
            )
            ->where('lookup_handle.handle = :handle')->setParameter('handle', strtolower($handle))
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    public function siteActor(): ?LocalActor
    {
        $row = $this->actorQuery()
            ->where('actor.actor_kind = :kind')->setParameter('kind', ActorKind::SITE->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    public function activeAuthorActor(int $authorId): ?LocalActor
    {
        if ($authorId <= 0) {
            return null;
        }

        $row = $this->actorQuery()
            ->where('actor.author_id = :author_id')->setParameter('author_id', $authorId)
            ->andWhere('actor.actor_kind = :kind')->setParameter('kind', ActorKind::AUTHOR->value)
            ->andWhere('actor.state = :state')->setParameter('state', LocalActorState::ACTIVE->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    public function authorActor(int $authorId): ?LocalActor
    {
        if ($authorId <= 0) {
            return null;
        }

        $row = $this->actorQuery()
            ->where('actor.author_id = :author_id')->setParameter('author_id', $authorId)
            ->andWhere('actor.actor_kind = :kind')->setParameter('kind', ActorKind::AUTHOR->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActor($row) : null;
    }

    /** @return list<LocalActor> */
    public function activeActors(): array
    {
        $rows = $this->actorQuery()
            ->where('actor.state = :state')->setParameter('state', LocalActorState::ACTIVE->value)
            ->orderBy('actor.actor_kind DESC, actor.display_name, actor.id')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrateActor(...), $rows));
    }

    /** @return list<LocalActor> */
    public function allActors(): array
    {
        $rows = $this->actorQuery()
            ->orderBy('actor.id')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrateActor(...), $rows));
    }

    /** @return list<string> Current handle first, followed by retained aliases. */
    public function handlesForActor(int $actorId): array
    {
        if ($actorId <= 0) {
            return [];
        }

        $rows = $this->dbLayer->select('handle')
            ->from(ActivityPubSchema::ACTOR_HANDLE_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->orderBy('is_current DESC', 'created_at DESC')
            ->execute()
            ->fetchColumn()
        ;

        return array_values(array_map(strval(...), $rows));
    }

    public function currentKey(int $actorId): ?LocalActorKey
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTOR_KEY_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('is_current = 1')
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateKey($row) : null;
    }

    public function keyByPublicId(string $publicId): ?LocalActorKey
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTOR_KEY_TABLE)
            ->where('public_id = :public_id')->setParameter('public_id', $publicId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateKey($row) : null;
    }

    public function publicActorCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::ACTOR_TABLE)
            ->where('state IN (:active, :moved, :tombstoned)')
            ->setParameter('active', LocalActorState::ACTIVE->value)
            ->setParameter('moved', LocalActorState::MOVED->value)
            ->setParameter('tombstoned', LocalActorState::TOMBSTONED->value)
            ->execute()
            ->result()
        ;
    }

    public function insert(NewLocalActor $actor, LocalActorState $state, int $now): int
    {
        $this->dbLayer->insert(ActivityPubSchema::ACTOR_TABLE)
            ->values([
                'public_id'      => ':public_id',
                'actor_kind'     => ':actor_kind',
                'site_slot'      => ':site_slot',
                'author_id'      => ':author_id',
                'actor_type'     => ':actor_type',
                'display_name'   => ':display_name',
                'summary_html'   => ':summary_html',
                'profile_url'    => ':profile_url',
                'avatar_data'    => ':avatar_data',
                'header_data'    => ':header_data',
                'metadata_json'  => ':metadata_json',
                'state'          => ':state',
                'discoverable'   => ':discoverable',
                'created_at'     => ':created_at',
                'activated_at'   => ':activated_at',
                'updated_at'     => ':updated_at',
            ])
            ->execute([
                'public_id'      => $actor->publicId,
                'actor_kind'     => $actor->kind->value,
                'site_slot'      => $actor->kind === ActorKind::SITE ? 1 : null,
                'author_id'      => $actor->authorId,
                'actor_type'     => $actor->type->value,
                'display_name'   => trim($actor->displayName),
                'summary_html'   => $actor->summaryHtml,
                'profile_url'    => $actor->profileUrl,
                'avatar_data'    => $this->encodeNullableMap($actor->avatar),
                'header_data'    => $this->encodeNullableMap($actor->header),
                'metadata_json'  => json_encode($actor->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'state'          => $state->value,
                'discoverable'   => (int)$actor->discoverable,
                'created_at'     => $now,
                'activated_at'   => $state === LocalActorState::ACTIVE ? $now : null,
                'updated_at'     => $now,
            ])
        ;

        $actorId = (int)$this->dbLayer->insertId();
        if ($actorId <= 0) {
            throw new \RuntimeException('Unable to obtain the new ActivityPub actor identifier.');
        }

        $this->dbLayer->insert(ActivityPubSchema::ACTOR_HANDLE_TABLE)
            ->values([
                'handle'     => ':handle',
                'actor_id'   => ':actor_id',
                'is_current' => '1',
                'created_at' => ':created_at',
            ])
            ->execute([
                'handle'     => $actor->handle->value,
                'actor_id'   => $actorId,
                'created_at' => $now,
            ])
        ;

        return $actorId;
    }

    public function activate(int $actorId, int $now): bool
    {
        return $this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('state', ':active')->setParameter('active', LocalActorState::ACTIVE->value)
            ->set('activated_at', ':activated_at')->setParameter('activated_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :draft')->setParameter('draft', LocalActorState::DRAFT->value)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function updateDraft(int $actorId, NewLocalActor $actor, int $now): LocalActor
    {
        if ($actorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('The ActivityPub actor draft update is invalid.');
        }

        $current = $this->findById($actorId);
        if (!$current instanceof LocalActor) {
            throw new \DomainException('Only the matching unpublished ActivityPub actor may be edited.');
        }

        if ($current->state !== LocalActorState::DRAFT
            || $current->kind !== $actor->kind
            || !hash_equals($current->publicId, $actor->publicId)
            || $current->authorId !== $actor->authorId
        ) {
            throw new \DomainException('Only the matching unpublished ActivityPub actor may be edited.');
        }

        $handleOwner = $this->findByHandle($actor->handle->value);
        if ($handleOwner instanceof LocalActor && $handleOwner->id !== $actorId) {
            throw new \DomainException('The requested ActivityPub handle is already assigned to another actor.');
        }

        if (!hash_equals($current->handle, $actor->handle->value)) {
            $deleted = $this->dbLayer->delete(ActivityPubSchema::ACTOR_HANDLE_TABLE)
                ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
                ->execute()
                ->affectedRows()
            ;
            if ($deleted !== 1) {
                throw new \RuntimeException('The unpublished ActivityPub actor has an invalid handle history.');
            }

            $this->dbLayer->insert(ActivityPubSchema::ACTOR_HANDLE_TABLE)
                ->values([
                    'handle'     => ':handle',
                    'actor_id'   => ':actor_id',
                    'is_current' => '1',
                    'created_at' => ':created_at',
                ])
                ->execute([
                    'handle'     => $actor->handle->value,
                    'actor_id'   => $actorId,
                    'created_at' => $now,
                ])
            ;
        }

        $updated = $this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('actor_type', ':actor_type')->setParameter('actor_type', $actor->type->value)
            ->set('display_name', ':display_name')->setParameter('display_name', trim($actor->displayName))
            ->set('summary_html', ':summary_html')->setParameter('summary_html', $actor->summaryHtml)
            ->set('profile_url', ':profile_url')->setParameter('profile_url', $actor->profileUrl)
            ->set('avatar_data', ':avatar_data')->setParameter('avatar_data', $this->encodeNullableMap($actor->avatar))
            ->set('header_data', ':header_data')->setParameter('header_data', $this->encodeNullableMap($actor->header))
            ->set('metadata_json', ':metadata_json')->setParameter(
                'metadata_json',
                json_encode($actor->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )
            ->set('discoverable', ':discoverable')->setParameter('discoverable', (int)$actor->discoverable)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :draft')->setParameter('draft', LocalActorState::DRAFT->value)
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            throw new \RuntimeException('The ActivityPub actor draft changed concurrently.');
        }

        return $this->findById($actorId)
            ?? throw new \RuntimeException('The updated ActivityPub actor draft cannot be reloaded.');
    }

    public function updateActiveProfile(int $actorId, NewLocalActor $actor, int $now): LocalActor
    {
        if ($actorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('The ActivityPub actor profile update is invalid.');
        }

        $current = $this->findById($actorId);
        if (!$current instanceof LocalActor) {
            throw new \DomainException('Only the matching active ActivityPub actor profile may be edited.');
        }

        if ($current->state !== LocalActorState::ACTIVE
            || $current->kind !== $actor->kind
            || !hash_equals($current->publicId, $actor->publicId)
            || $current->authorId !== $actor->authorId
            || !hash_equals($current->handle, $actor->handle->value)
        ) {
            throw new \DomainException('Only the matching active ActivityPub actor profile may be edited.');
        }

        $updated = $this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('actor_type', ':actor_type')->setParameter('actor_type', $actor->type->value)
            ->set('display_name', ':display_name')->setParameter('display_name', trim($actor->displayName))
            ->set('summary_html', ':summary_html')->setParameter('summary_html', $actor->summaryHtml)
            ->set('profile_url', ':profile_url')->setParameter('profile_url', $actor->profileUrl)
            ->set('avatar_data', ':avatar_data')->setParameter('avatar_data', $this->encodeNullableMap($actor->avatar))
            ->set('header_data', ':header_data')->setParameter('header_data', $this->encodeNullableMap($actor->header))
            ->set('metadata_json', ':metadata_json')->setParameter(
                'metadata_json',
                json_encode($actor->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )
            ->set('discoverable', ':discoverable')->setParameter('discoverable', (int)$actor->discoverable)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :active')->setParameter('active', LocalActorState::ACTIVE->value)
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            throw new \RuntimeException('The active ActivityPub actor profile changed concurrently.');
        }

        return $this->findById($actorId)
            ?? throw new \RuntimeException('The updated active ActivityPub actor cannot be reloaded.');
    }

    public function insertKey(
        int                 $actorId,
        string              $publicId,
        string              $publicKeyPem,
        EncryptedPrivateKey $privateKey,
        int                 $now,
    ): void {
        $this->dbLayer->insert(ActivityPubSchema::ACTOR_KEY_TABLE)
            ->values([
                'actor_id'               => ':actor_id',
                'public_id'              => ':public_id',
                'algorithm'              => ':algorithm',
                'public_key_pem'         => ':public_key_pem',
                'private_key_ciphertext' => ':private_key_ciphertext',
                'private_key_nonce'      => ':private_key_nonce',
                'is_current'             => '1',
                'created_at'             => ':created_at',
            ])
            ->execute([
                'actor_id'               => $actorId,
                'public_id'              => $publicId,
                'algorithm'              => 'rsa-sha256',
                'public_key_pem'         => $publicKeyPem,
                'private_key_ciphertext' => $privateKey->ciphertext,
                'private_key_nonce'      => $privateKey->nonce,
                'created_at'             => $now,
            ])
        ;
    }

    public function replaceCurrentKey(
        int                 $actorId,
        string              $publicId,
        string              $publicKeyPem,
        EncryptedPrivateKey $privateKey,
        int                 $now,
    ): LocalActorKey {
        $retired = $this->dbLayer->update(ActivityPubSchema::ACTOR_KEY_TABLE)
            ->set('is_current', '0')
            ->set('retired_at', ':retired_at')->setParameter('retired_at', $now)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('is_current = 1')
            ->andWhere('destroyed_at IS NULL')
            ->execute()
            ->affectedRows()
        ;
        if ($retired !== 1) {
            throw new \RuntimeException('The ActivityPub actor does not have exactly one current key to rotate.');
        }

        $this->insertKey($actorId, $publicId, $publicKeyPem, $privateKey, $now);

        return $this->currentKey($actorId)
            ?? throw new \RuntimeException('The rotated ActivityPub key cannot be reloaded.');
    }

    public function changeHandle(int $actorId, LocalHandle $handle, int $now): LocalActor
    {
        if ($actorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('An ActivityPub handle transition is invalid.');
        }

        $owner = $this->findByHandle($handle->value);
        if ($owner instanceof LocalActor && $owner->id !== $actorId) {
            throw new \DomainException('The requested ActivityPub handle is already assigned to another actor.');
        }

        $current = $this->findById($actorId);
        if (!$current instanceof LocalActor) {
            throw new \DomainException('Only an active ActivityPub actor can change its handle.');
        }

        if ($current->state !== LocalActorState::ACTIVE) {
            throw new \DomainException('Only an active ActivityPub actor can change its handle.');
        }

        if (hash_equals($current->handle, $handle->value)) {
            return $current;
        }

        $retired = $this->dbLayer->update(ActivityPubSchema::ACTOR_HANDLE_TABLE)
            ->set('is_current', '0')
            ->set('retired_at', ':retired_at')->setParameter('retired_at', $now)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('is_current = 1')
            ->execute()
            ->affectedRows()
        ;
        if ($retired !== 1) {
            throw new \RuntimeException('The ActivityPub actor does not have exactly one current handle.');
        }

        $reactivated = $this->dbLayer->update(ActivityPubSchema::ACTOR_HANDLE_TABLE)
            ->set('is_current', '1')
            ->set('retired_at', 'NULL')
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('handle = :handle')->setParameter('handle', $handle->value)
            ->execute()
            ->affectedRows()
        ;
        if ($reactivated === 0) {
            $this->dbLayer->insert(ActivityPubSchema::ACTOR_HANDLE_TABLE)
                ->values([
                    'handle'     => ':handle',
                    'actor_id'   => ':actor_id',
                    'is_current' => '1',
                    'created_at' => ':created_at',
                ])
                ->execute([
                    'handle'     => $handle->value,
                    'actor_id'   => $actorId,
                    'created_at' => $now,
                ])
            ;
        } elseif ($reactivated !== 1) {
            throw new \RuntimeException('The ActivityPub handle history is ambiguous.');
        }

        if ($this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :state')->setParameter('state', LocalActorState::ACTIVE->value)
            ->execute()
            ->affectedRows() !== 1
        ) {
            throw new \RuntimeException('The ActivityPub actor changed concurrently with its handle.');
        }

        $updated = $this->findById($actorId);
        if (!$updated instanceof LocalActor || !hash_equals($updated->handle, $handle->value)) {
            throw new \RuntimeException('The changed ActivityPub handle cannot be reloaded consistently.');
        }

        return $updated;
    }

    public function markMoved(int $actorId, string $targetActorUrl, int $now): bool
    {
        $this->validateRemoteActorUrl($targetActorUrl);
        if ($actorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('An ActivityPub actor Move transition is invalid.');
        }

        return $this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('state', ':moved')->setParameter('moved', LocalActorState::MOVED->value)
            ->set('moved_to_url', ':moved_to_url')->setParameter('moved_to_url', $targetActorUrl)
            ->set('moved_at', ':moved_at')->setParameter('moved_at', $now)
            ->set('deactivated_at', ':deactivated_at')->setParameter('deactivated_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :active')->setParameter('active', LocalActorState::ACTIVE->value)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    /** @return list<LocalActorKey> */
    public function keysForActor(int $actorId): array
    {
        if ($actorId < 1) {
            return [];
        }

        $rows = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTOR_KEY_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->orderBy('created_at DESC, id DESC')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrateKey(...), $rows));
    }

    public function tombstoneActiveActors(int $now): int
    {
        return $this->dbLayer->update(ActivityPubSchema::ACTOR_TABLE)
            ->set('state', ':tombstoned')->setParameter('tombstoned', LocalActorState::TOMBSTONED->value)
            ->set('deactivated_at', ':deactivated_at')->setParameter('deactivated_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state = :active')->setParameter('active', LocalActorState::ACTIVE->value)
            ->execute()
            ->affectedRows()
        ;
    }

    private function actorQuery(): \Register\Core\Pdo\QueryBuilder\SelectBuilder
    {
        return $this->dbLayer->select('actor.*, handle.handle')
            ->from(ActivityPubSchema::ACTOR_TABLE . ' AS actor')
            ->innerJoin(
                ActivityPubSchema::ACTOR_HANDLE_TABLE . ' AS handle',
                'handle.actor_id = actor.id AND handle.is_current = 1',
            )
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrateActor(array $row): LocalActor
    {
        try {
            return new LocalActor(
                (int)$row['id'],
                (string)$row['public_id'],
                ActorKind::from((string)$row['actor_kind']),
                $row['author_id'] === null ? null : (int)$row['author_id'],
                ActorType::from((string)$row['actor_type']),
                (string)$row['handle'],
                (string)$row['display_name'],
                (string)$row['summary_html'],
                (string)$row['profile_url'],
                $this->decodeNullableMap($row['avatar_data']),
                $this->decodeNullableMap($row['header_data']),
                $this->decodeMetadata((string)$row['metadata_json']),
                LocalActorState::from((string)$row['state']),
                $row['moved_to_url'] === null ? null : (string)$row['moved_to_url'],
                $row['moved_at'] === null ? null : (int)$row['moved_at'],
                (bool)$row['discoverable'],
                (int)$row['created_at'],
                $row['activated_at'] === null ? null : (int)$row['activated_at'],
                $row['deactivated_at'] === null ? null : (int)$row['deactivated_at'],
                (int)$row['updated_at'],
            );
        } catch (\ValueError | \JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub actor is invalid.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateKey(array $row): LocalActorKey
    {
        return new LocalActorKey(
            (int)$row['id'],
            (int)$row['actor_id'],
            (string)$row['public_id'],
            (string)$row['algorithm'],
            (string)$row['public_key_pem'],
            new EncryptedPrivateKey((string)$row['private_key_ciphertext'], (string)$row['private_key_nonce']),
            (bool)$row['is_current'],
            (int)$row['created_at'],
            $row['retired_at'] === null ? null : (int)$row['retired_at'],
            $row['destroyed_at'] === null ? null : (int)$row['destroyed_at'],
        );
    }

    /** @param array<string, scalar|null>|null $value */
    private function encodeNullableMap(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, scalar|null>|null */
    private function decodeNullableMap(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string)$value, true, 8, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \JsonException('Expected an ActivityPub media object.');
        }

        /** @var array<string, scalar|null> $decoded */
        return $decoded;
    }

    /** @return list<array{name: string, value: string}> */
    private function decodeMetadata(string $value): array
    {
        $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \JsonException('Expected an ActivityPub profile metadata list.');
        }

        $result = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !\is_string($entry['name'] ?? null) || !\is_string($entry['value'] ?? null)) {
                throw new \JsonException('An ActivityPub profile metadata entry is invalid.');
            }

            $result[] = ['name' => $entry['name'], 'value' => $entry['value']];
        }

        return $result;
    }

    private function validateRemoteActorUrl(string $url): void
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub Move target must be a bounded credential-free HTTPS actor URL.');
        }
    }
}
