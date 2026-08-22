<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\InboxState;

/** Durable first-seen inbox envelope storage, claims, and crash recovery. */
final readonly class InboxRepository
{
    private const int RAW_RETENTION_SECONDS = 7 * 24 * 60 * 60;

    private const int STALE_CLAIM_SECONDS = 2 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function receive(NewInboxItem $item): InboxReceipt
    {
        $result = $this->dbLayer->insert(ActivityPubSchema::INBOX_TABLE)
            ->values([
                'deduplication_hash'       => ':deduplication_hash',
                'target_local_actor_id'    => ':target_local_actor_id',
                'activity_type'            => ':activity_type',
                'activity_url'             => ':activity_url',
                'activity_url_hash'        => ':activity_url_hash',
                'body_hash'                => ':body_hash',
                'actor_url'                => ':actor_url',
                'actor_url_hash'           => ':actor_url_hash',
                'key_id'                   => ':key_id',
                'signature_type'           => ':signature_type',
                'effective_origin'         => ':effective_origin',
                'raw_body'                 => ':raw_body',
                'transport_json'           => ':transport_json',
                'state'                    => ':state',
                'attempt_count'            => '0',
                'key_refresh_count'        => '0',
                'force_key_refresh'        => '0',
                'fetch_kind'               => ':fetch_kind',
                'fetch_signed'             => '0',
                'fetch_url'                => ':fetch_url',
                'fetch_redirect_count'     => '0',
                'fetch_redirect_chain_json' => ':fetch_redirect_chain_json',
                'fetched_object_json'      => ':fetched_object_json',
                'fetched_object_hash'      => ':fetched_object_hash',
                'available_at'             => ':available_at',
                'received_at'              => ':received_at',
                'raw_expires_at'           => ':raw_expires_at',
                'error_code'               => ':error_code',
                'result_detail'            => ':result_detail',
            ])
            ->onConflictDoNothing('deduplication_hash')
            ->execute([
                'deduplication_hash'        => $item->deduplicationHash,
                'target_local_actor_id'     => $item->targetLocalActorId,
                'activity_type'             => $item->activityType,
                'activity_url'              => $item->activityUrl,
                'activity_url_hash'         => $item->activityUrlHash,
                'body_hash'                 => $item->bodyHash,
                'actor_url'                 => $item->actorUrl,
                'actor_url_hash'            => $item->actorUrlHash,
                'key_id'                    => $item->keyId,
                'signature_type'            => $item->signatureType,
                'effective_origin'          => $item->effectiveOrigin,
                'raw_body'                  => $item->rawBody,
                'transport_json'            => $item->transportJson,
                'state'                     => InboxState::RECEIVED->value,
                'fetch_kind'                => 'actor',
                'fetch_url'                 => $item->actorUrl,
                'fetch_redirect_chain_json' => '[]',
                'fetched_object_json'       => '',
                'fetched_object_hash'       => '',
                'available_at'              => $item->receivedAt,
                'received_at'               => $item->receivedAt,
                'raw_expires_at'            => $item->receivedAt + self::RAW_RETENTION_SECONDS,
                'error_code'                => '',
                'result_detail'             => '',
            ])
        ;
        $inserted = $result->affectedRows() === 1;
        if ($inserted) {
            $id = (int)$this->dbLayer->insertId();
            if ($id < 1) {
                throw new \RuntimeException('Unable to obtain the ActivityPub inbox receipt identifier.');
            }

            return new InboxReceipt($id, true);
        }

        $row = $this->dbLayer->select('id, activity_url, actor_url, body_hash')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('deduplication_hash = :deduplication_hash')
            ->setParameter('deduplication_hash', $item->deduplicationHash)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)
            || !hash_equals((string)$row['activity_url'], $item->activityUrl)
            || !hash_equals((string)$row['actor_url'], $item->actorUrl)
        ) {
            throw new \RuntimeException('An ActivityPub inbox deduplication SHA-256 collision was detected.');
        }

        // First-seen bytes remain authoritative if a peer reuses an activity id with another body.
        return new InboxReceipt((int)$row['id'], false);
    }

    public function recoverStaleClaims(int $now): int
    {
        return $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('state', ':delayed')->setParameter('delayed', InboxState::DELAYED->value)
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->set('available_at', ':available_at')->setParameter('available_at', $now)
            ->set('error_code', ':error_code')->setParameter('error_code', 'stale_claim')
            ->set('result_detail', ':detail')->setParameter('detail', 'Recovered after a PHP process stopped during inbox work.')
            ->where('state = :in_flight')->setParameter('in_flight', InboxState::IN_FLIGHT->value)
            ->andWhere('claimed_at < :stale_before')->setParameter('stale_before', max(0, $now - self::STALE_CLAIM_SECONDS))
            ->execute()
            ->affectedRows()
        ;
    }

    public function ignoreOutstanding(string $detail, int $now): int
    {
        if ($detail === '' || \strlen($detail) > 1_024 || $now < 1) {
            throw new \InvalidArgumentException('An ActivityPub inbox cancellation is invalid.');
        }

        $inFlight = (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('state = :state')->setParameter('state', InboxState::IN_FLIGHT->value)
            ->execute()
            ->result()
        ;
        if ($inFlight > 0) {
            throw new \DomainException('ActivityPub decommission must wait for the current inbox claim to finish.');
        }

        return $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('state', ':ignored')->setParameter('ignored', InboxState::IGNORED->value)
            ->set('processed_at', ':processed_at')->setParameter('processed_at', $now)
            ->set('error_code', ':error_code')->setParameter('error_code', 'decommissioned')
            ->set('result_detail', ':detail')->setParameter('detail', $detail)
            ->where('state IN (:received, :delayed)')
            ->setParameter('received', InboxState::RECEIVED->value)
            ->setParameter('delayed', InboxState::DELAYED->value)
            ->execute()
            ->affectedRows()
        ;
    }

    public function claimNext(int $now): ?ClaimedInboxItem
    {
        $this->expireOverdue($now);
        $row = $this->dbLayer->select('id, state')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('state IN (:received, :delayed)')
            ->setParameter('received', InboxState::RECEIVED->value)
            ->setParameter('delayed', InboxState::DELAYED->value)
            ->andWhere('available_at <= :available_at')->setParameter('available_at', $now)
            ->andWhere('raw_expires_at > :raw_expires_at')->setParameter('raw_expires_at', $now)
            ->andWhere('claim_token IS NULL')
            ->orderBy('available_at', 'id')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        $token = sodium_bin2base64(random_bytes(16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $updated = $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('state', ':in_flight')->setParameter('in_flight', InboxState::IN_FLIGHT->value)
            ->set('claim_token', ':claim_token')->setParameter('claim_token', $token)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', $now)
            ->set('attempt_count', 'attempt_count + 1')
            ->where('id = :id')->setParameter('id', (int)$row['id'])
            ->andWhere('state = :old_state')->setParameter('old_state', (string)$row['state'])
            ->andWhere('available_at <= :available_at')->setParameter('available_at', $now)
            ->andWhere('claim_token IS NULL')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            return null;
        }

        $claimed = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('claim_token = :claim_token')->setParameter('claim_token', $token)
            ->andWhere('state = :state')->setParameter('state', InboxState::IN_FLIGHT->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($claimed) ? $this->hydrateClaimed($claimed) : null;
    }

    public function markDelayed(
        ClaimedInboxItem $item,
        int              $availableAt,
        string           $errorCode,
        string           $detail,
        int              $now,
    ): void {
        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            $errorCode,
            $detail,
            ['available_at' => max($now + 1, $availableAt)],
        );
    }

    public function markActorFetched(ClaimedInboxItem $item, int $now): void
    {
        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            '',
            'The remote actor cache was refreshed; signature verification is scheduled.',
            [
                'available_at'              => $now + 1,
                'force_key_refresh'         => 0,
                'fetch_kind'                => $item->fetchedObjectJson === '' ? 'actor' : 'ready',
                'fetch_signed'              => 0,
                'fetch_url'                 => $item->actorUrl,
                'fetch_redirect_count'      => 0,
                'fetch_redirect_chain_json' => '[]',
            ],
        );
    }

    public function requestKeyRefresh(ClaimedInboxItem $item, string $detail, int $now): void
    {
        if ($item->keyRefreshCount > 0) {
            throw new \DomainException('The ActivityPub inbox item has exhausted its key refresh.');
        }

        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            'key_refresh',
            $detail,
            [
                'available_at'              => $now + 1,
                'key_refresh_count'         => 1,
                'force_key_refresh'         => 1,
                'fetch_kind'                => 'actor',
                'fetch_signed'              => 0,
                'fetch_url'                 => $item->actorUrl,
                'fetch_redirect_count'      => 0,
                'fetch_redirect_chain_json' => '[]',
            ],
        );
    }

    public function markFetchRedirected(
        ClaimedInboxItem $item,
        string           $fetchKind,
        string           $currentUrl,
        string           $redirectUrl,
        int              $now,
    ): void
    {
        if (!\in_array($fetchKind, ['actor', 'object', 'move_target'], true)) {
            throw new \InvalidArgumentException('The ActivityPub fetch redirect kind is invalid.');
        }

        $this->validateHttpsUrl($currentUrl);
        $this->validateHttpsUrl($redirectUrl);
        if (\in_array($redirectUrl, [$currentUrl, ...$item->fetchRedirectChain], true)) {
            throw new \DomainException('The remote actor redirect contains a loop.');
        }

        $chain = [...$item->fetchRedirectChain, $currentUrl];
        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            'redirect',
            'Following a validated remote actor redirect as a separate network hop.',
            [
                'available_at'              => $now + 1,
                'fetch_kind'                => $fetchKind,
                'fetch_url'                 => $redirectUrl,
                'fetch_redirect_count'      => $item->fetchRedirectCount + 1,
                'fetch_redirect_chain_json' => json_encode($chain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    public function markObjectFetched(ClaimedInboxItem $item, string $json, int $now): void
    {
        if ($json === '' || \strlen($json) > \s2_extensions\activitypub\Domain\ProtocolLimits::OBJECT_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('The fetched remote ActivityPub object is empty or too large.');
        }

        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            '',
            'The remote object snapshot was fetched; local processing is scheduled.',
            [
                'available_at'              => $now + 1,
                'fetch_kind'                => 'ready',
                'fetch_signed'              => 0,
                'fetch_url'                 => $item->actorUrl,
                'fetch_redirect_count'      => 0,
                'fetch_redirect_chain_json' => '[]',
                'fetched_object_json'       => $json,
                'fetched_object_hash'       => hash('sha256', $json),
            ],
        );
    }

    public function markMoveTargetFetched(ClaimedInboxItem $item, string $json, int $now): void
    {
        if ($json === '' || \strlen($json) > \s2_extensions\activitypub\Domain\ProtocolLimits::ACTOR_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('The fetched ActivityPub Move target actor is empty or too large.');
        }

        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            '',
            'The ActivityPub Move target was fetched; alias verification is scheduled.',
            [
                'available_at'              => $now + 1,
                'fetch_kind'                => 'ready',
                'fetch_signed'              => 0,
                'fetch_url'                 => $item->actorUrl,
                'fetch_redirect_count'      => 0,
                'fetch_redirect_chain_json' => '[]',
                'fetched_object_json'       => $json,
                'fetched_object_hash'       => hash('sha256', $json),
            ],
        );
    }

    public function requestSignedFetch(
        ClaimedInboxItem $item,
        string           $fetchKind,
        string           $fetchUrl,
        int              $now,
    ): void {
        if ($item->fetchSigned || !\in_array($fetchKind, ['actor', 'object', 'move_target'], true)) {
            throw new \DomainException('The ActivityPub signed-fetch compatibility retry is exhausted.');
        }

        $this->validateHttpsUrl($fetchUrl);
        $this->completeClaim(
            $item,
            InboxState::DELAYED,
            'signed_fetch',
            'The peer requires a signed GET; one compatibility retry is scheduled.',
            [
                'available_at' => $now + 1,
                'fetch_kind'   => $fetchKind,
                'fetch_signed' => 1,
                'fetch_url'    => $fetchUrl,
            ],
        );
    }

    public function markTerminal(
        ClaimedInboxItem $item,
        InboxState       $state,
        string           $errorCode,
        string           $detail,
        int              $now,
        ?string          $verifiedKeyId = null,
    ): void {
        if (!\in_array($state, [InboxState::PROCESSED, InboxState::IGNORED, InboxState::FAILED], true)) {
            throw new \InvalidArgumentException('The ActivityPub inbox terminal state is invalid.');
        }

        $extra = ['processed_at' => $now];
        if ($verifiedKeyId !== null) {
            $extra['key_id'] = $verifiedKeyId;
        }

        $this->completeClaim($item, $state, $errorCode, $detail, $extra);
    }

    public function earliestPendingAt(): ?int
    {
        $value = $this->dbLayer->select('MIN(available_at)')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('state IN (:received, :delayed)')
            ->setParameter('received', InboxState::RECEIVED->value)
            ->setParameter('delayed', InboxState::DELAYED->value)
            ->execute()
            ->result()
        ;

        return $value === null || $value === false ? null : (int)$value;
    }

    /** @param array<string, mixed> $row */
    private function hydrateClaimed(array $row): ClaimedInboxItem
    {
        try {
            $redirectChain = json_decode((string)$row['fetch_redirect_chain_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!\is_array($redirectChain) || !array_is_list($redirectChain)) {
                throw new \JsonException('Expected an ActivityPub redirect list.');
            }

            $redirects = [];
            foreach ($redirectChain as $redirect) {
                if (!\is_string($redirect) || $redirect === '') {
                    throw new \JsonException('Expected an ActivityPub redirect string.');
                }

                $redirects[] = $redirect;
            }

            return new ClaimedInboxItem(
                (int)$row['id'],
                $row['target_local_actor_id'] === null ? null : (int)$row['target_local_actor_id'],
                (string)$row['activity_type'],
                (string)$row['activity_url'],
                (string)$row['actor_url'],
                (string)$row['key_id'],
                (string)$row['signature_type'],
                (string)$row['effective_origin'],
                (string)$row['raw_body'],
                (string)$row['body_hash'],
                (string)$row['transport_json'],
                (int)$row['attempt_count'],
                (int)$row['key_refresh_count'],
                (bool)$row['force_key_refresh'],
                (string)$row['fetch_kind'],
                (bool)$row['fetch_signed'],
                (string)$row['fetch_url'],
                (int)$row['fetch_redirect_count'],
                $redirects,
                (string)$row['fetched_object_json'],
                (string)$row['fetched_object_hash'],
                (int)$row['raw_expires_at'],
                (string)$row['claim_token'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub inbox item is invalid.', 0, $exception);
        }
    }

    /** @param array<string, int|string> $extra */
    private function completeClaim(
        ClaimedInboxItem $item,
        InboxState       $state,
        string           $errorCode,
        string           $detail,
        array            $extra = [],
    ): void {
        $query = $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('state', ':state')->setParameter('state', $state->value)
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->set('error_code', ':error_code')->setParameter('error_code', mb_substr($errorCode, 0, 64))
            ->set('result_detail', ':result_detail')->setParameter('result_detail', mb_substr($detail, 0, 4_000))
            ->where('id = :id')->setParameter('id', $item->id)
            ->andWhere('state = :in_flight')->setParameter('in_flight', InboxState::IN_FLIGHT->value)
            ->andWhere('claim_token = :claim_token')->setParameter('claim_token', $item->claimToken)
        ;
        foreach ($extra as $column => $value) {
            $parameter = 'extra_' . $column;
            $query->set($column, ':' . $parameter)->setParameter($parameter, $value);
        }

        if ($query->execute()->affectedRows() !== 1) {
            throw new \RuntimeException('The ActivityPub inbox claim was lost before completion.');
        }
    }

    private function expireOverdue(int $now): void
    {
        $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('state', ':failed')->setParameter('failed', InboxState::FAILED->value)
            ->set('processed_at', ':processed_at')->setParameter('processed_at', $now)
            ->set('error_code', ':error_code')->setParameter('error_code', 'expired')
            ->set('result_detail', ':detail')->setParameter('detail', 'The raw inbox verification retention deadline elapsed.')
            ->where('state IN (:received, :delayed)')
            ->setParameter('received', InboxState::RECEIVED->value)
            ->setParameter('delayed', InboxState::DELAYED->value)
            ->andWhere('raw_expires_at <= :raw_expires_at')->setParameter('raw_expires_at', $now)
            ->execute()
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
            throw new \InvalidArgumentException('A remote actor fetch URL must be bounded credential-free HTTPS.');
        }
    }
}
