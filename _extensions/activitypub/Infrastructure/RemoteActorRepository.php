<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\RemoteActor;
use s2_extensions\activitypub\Application\RemoteAvatarScheduler;

final readonly class RemoteActorRepository
{
    public function __construct(private DbLayer $dbLayer, private ?RemoteAvatarScheduler $avatarScheduler = null)
    {
    }

    public function findByUrl(string $actorUrl): ?RemoteActor
    {
        $hash = hash('sha256', $actorUrl);
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->where('url_hash = :url_hash')->setParameter('url_hash', $hash)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        if (!hash_equals((string)$row['actor_url'], $actorUrl)) {
            throw new \RuntimeException('A remote ActivityPub actor URL SHA-256 collision was detected.');
        }

        return $this->hydrate($row);
    }

    public function findById(int $id): ?RemoteActor
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(FetchedRemoteActor $actor): RemoteActor
    {
        $stored = $this->findByUrl($actor->actorUrl);
        if (!$stored instanceof RemoteActor) {
            $parameters = $this->parameters($actor, null);
            $this->dbLayer->insert(ActivityPubSchema::REMOTE_ACTOR_TABLE)
                ->values([
                    'url_hash'              => ':url_hash',
                    'actor_url'             => ':actor_url',
                    'actor_type'            => ':actor_type',
                    'preferred_username'    => ':preferred_username',
                    'display_name'          => ':display_name',
                    'avatar_url'            => ':avatar_url',
                    'inbox_url'             => ':inbox_url',
                    'shared_inbox_url'      => ':shared_inbox_url',
                    'featured_url'          => ':featured_url',
                    'public_key_id'         => ':public_key_id',
                    'public_key_pem'        => ':public_key_pem',
                    'also_known_as_json'    => ':also_known_as_json',
                    'state'                 => ':state',
                    'moved_to_url'          => ':moved_to_url',
                    'moved_at'              => ':moved_at',
                    'failure_count'         => '0',
                    'fetched_at'            => ':fetched_at',
                    'expires_at'            => ':expires_at',
                    'created_at'            => ':created_at',
                    'updated_at'            => ':updated_at',
                ])
                ->onConflictDoNothing('url_hash')
                ->execute($parameters)
            ;
            $stored = $this->findByUrl($actor->actorUrl);
        }

        if (!$stored instanceof RemoteActor) {
            throw new \RuntimeException('The remote ActivityPub actor could not be stored.');
        }

        $parameters = $this->parameters($actor, $stored);
        $updated = $this->dbLayer->update(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->set('actor_type', ':actor_type')
            ->set('preferred_username', ':preferred_username')
            ->set('display_name', ':display_name')
            ->set('avatar_url', ':avatar_url')
            ->set('inbox_url', ':inbox_url')
            ->set('shared_inbox_url', ':shared_inbox_url')
            ->set('featured_url', ':featured_url')
            ->set('public_key_id', ':public_key_id')
            ->set('public_key_pem', ':public_key_pem')
            ->set('also_known_as_json', ':also_known_as_json')
            ->set('state', ':state')
            ->set('moved_to_url', ':moved_to_url')
            ->set('moved_at', ':moved_at')
            ->set('failure_count', '0')
            ->set('fetched_at', ':fetched_at')
            ->set('expires_at', ':expires_at')
            ->set('updated_at', ':updated_at')
            ->where('id = :id')->setParameter('id', $stored->id)
        ;
        foreach ($parameters as $name => $value) {
            if (!\in_array($name, ['url_hash', 'actor_url', 'created_at'], true)) {
                $updated->setParameter($name, $value);
            }
        }

        $updated->execute();

        $this->dbLayer->insert(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->values([
                'subject_type'      => ':subject_type',
                'subject_id'        => ':subject_id',
                'body_hash'         => ':body_hash',
                'document_json'     => ':document_json',
                'verification_state' => ':verification_state',
                'fetched_at'        => ':fetched_at',
                'retain_until'      => ':retain_until',
            ])
            ->onConflictDoNothing('subject_type', 'subject_id', 'body_hash')
            ->execute([
                'subject_type'       => 'actor',
                'subject_id'         => $stored->id,
                'body_hash'          => $actor->snapshotHash,
                'document_json'      => $actor->snapshotJson,
                'verification_state' => 'validated',
                'fetched_at'         => $actor->fetchedAt,
                'retain_until'       => $actor->fetchedAt + 30 * 24 * 60 * 60,
            ])
        ;
        $snapshotId = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->where('subject_type = :subject_type')->setParameter('subject_type', 'actor')
            ->andWhere('subject_id = :subject_id')->setParameter('subject_id', $stored->id)
            ->andWhere('body_hash = :body_hash')->setParameter('body_hash', $actor->snapshotHash)
            ->execute()
            ->result()
        ;
        if ($snapshotId === null || $snapshotId === false) {
            throw new \RuntimeException('The remote ActivityPub actor snapshot could not be stored.');
        }

        $this->dbLayer->update(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->set('current_snapshot_id', ':snapshot_id')->setParameter('snapshot_id', (int)$snapshotId)
            ->where('id = :id')->setParameter('id', $stored->id)
            ->execute()
        ;

        $saved = $this->findByUrl($actor->actorUrl);
        if (!$saved instanceof RemoteActor) {
            throw new \RuntimeException('The stored remote ActivityPub actor cannot be reloaded consistently.');
        }

        if ($saved->fetchedAt !== $actor->fetchedAt
            || !hash_equals($saved->publicKeyId, $actor->publicKeyId)
            || !hash_equals($saved->publicKeyPem, $actor->publicKeyPem)
            || $saved->movedToUrl !== $parameters['moved_to_url']
            || $saved->avatarUrl !== $actor->avatarUrl
            || $saved->featuredUrl !== $actor->featuredUrl
        ) {
            throw new \RuntimeException('The stored remote ActivityPub actor cannot be reloaded consistently.');
        }

        $this->avatarScheduler?->synchronize($saved->id, $saved->avatarUrl, $actor->fetchedAt);

        return $saved;
    }

    public function markMoved(int $actorId, string $targetActorUrl, int $now): bool
    {
        if ($actorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('A remote ActivityPub actor Move transition is invalid.');
        }

        $actor = $this->findById($actorId);
        if (!$actor instanceof RemoteActor) {
            throw new \DomainException('The remote ActivityPub actor to move does not exist.');
        }

        $this->validateActorUrl($targetActorUrl);
        if (hash_equals($actor->actorUrl, $targetActorUrl)) {
            throw new \DomainException('A remote ActivityPub actor cannot move to itself.');
        }

        if ($actor->state === 'moved' && hash_equals($actor->movedToUrl ?? '', $targetActorUrl)) {
            return false;
        }

        if ($actor->state !== 'active') {
            throw new \DomainException('Only an active remote ActivityPub actor can be moved.');
        }

        return $this->dbLayer->update(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->set('state', ':state')->setParameter('state', 'moved')
            ->set('moved_to_url', ':moved_to_url')->setParameter('moved_to_url', $targetActorUrl)
            ->set('moved_at', ':moved_at')->setParameter('moved_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $actorId)
            ->andWhere('state = :active')->setParameter('active', 'active')
            ->execute()
            ->affectedRows() === 1
        ;
    }

    /** @return array<string, int|string|null> */
    private function parameters(FetchedRemoteActor $actor, ?RemoteActor $stored): array
    {
        $movedToUrl = $actor->movedToUrl;
        if ($movedToUrl === null && $stored instanceof RemoteActor) {
            if ($stored->state === 'moved') {
                $movedToUrl = $stored->movedToUrl;
            }
        }

        $movedAt = null;
        if ($movedToUrl !== null) {
            $movedAt = $actor->fetchedAt;
            if ($stored instanceof RemoteActor) {
                if ($stored->movedToUrl === $movedToUrl) {
                    $movedAt = $stored->movedAt;
                }
            }
        }

        $state = $movedToUrl === null ? 'active' : 'moved';
        if ($stored instanceof RemoteActor) {
            if ($stored->state === 'blocked') {
                $state = 'blocked';
            }
        }

        return [
            'url_hash'           => hash('sha256', $actor->actorUrl),
            'actor_url'          => $actor->actorUrl,
            'actor_type'         => $actor->actorType,
            'preferred_username' => $actor->preferredUsername,
            'display_name'       => $actor->displayName,
            'avatar_url'         => $actor->avatarUrl,
            'inbox_url'          => $actor->inboxUrl,
            'shared_inbox_url'   => $actor->sharedInboxUrl,
            'featured_url'       => $actor->featuredUrl,
            'public_key_id'      => $actor->publicKeyId,
            'public_key_pem'     => $actor->publicKeyPem,
            'also_known_as_json' => json_encode($actor->alsoKnownAs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'state'              => $state,
            'moved_to_url'       => $movedToUrl,
            'moved_at'           => $movedAt,
            'fetched_at'         => $actor->fetchedAt,
            'expires_at'         => $actor->expiresAt,
            'created_at'         => $actor->fetchedAt,
            'updated_at'         => $actor->fetchedAt,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RemoteActor
    {
        try {
            $aliases = json_decode((string)$row['also_known_as_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!\is_array($aliases) || !array_is_list($aliases)) {
                throw new \JsonException('Expected a remote ActivityPub actor alias list.');
            }

            $alsoKnownAs = [];
            foreach ($aliases as $alias) {
                if (!\is_string($alias)) {
                    throw new \JsonException('Expected a remote ActivityPub actor alias string.');
                }

                $alsoKnownAs[] = $alias;
            }

            return new RemoteActor(
                (int)$row['id'],
                (string)$row['actor_url'],
                (string)$row['actor_type'],
                (string)$row['preferred_username'],
                (string)$row['display_name'],
                (string)$row['inbox_url'],
                $row['shared_inbox_url'] === null ? null : (string)$row['shared_inbox_url'],
                (string)$row['public_key_id'],
                (string)$row['public_key_pem'],
                $alsoKnownAs,
                (string)$row['state'],
                (int)$row['failure_count'],
                (int)$row['fetched_at'],
                (int)$row['expires_at'],
                $row['moved_to_url'] === null ? null : (string)$row['moved_to_url'],
                $row['moved_at'] === null ? null : (int)$row['moved_at'],
                $row['avatar_url'] === null ? null : (string)$row['avatar_url'],
                $row['featured_url'] === null ? null : (string)$row['featured_url'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored remote ActivityPub actor is invalid.', 0, $exception);
        }
    }

    private function validateActorUrl(string $url): void
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
            throw new \InvalidArgumentException('A remote ActivityPub actor URL must be bounded credential-free HTTPS.');
        }
    }
}
