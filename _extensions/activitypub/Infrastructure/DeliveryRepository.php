<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\DeliveryState;

/** Durable fan-out, claim, recovery, and terminal-state transitions for outgoing POSTs. */
final readonly class DeliveryRepository
{
    private const int DELIVERY_TTL_SECONDS = 7 * 24 * 60 * 60;

    private const int STALE_CLAIM_SECONDS = 2 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @param list<int> $localActorIds */
    public function planFollowers(
        StoredActivityRepresentation $activity,
        int                          $now,
        array                        $localActorIds = [],
    ): int
    {
        if ($activity->deliveryIntent !== ActivityDeliveryIntent::FOLLOWERS) {
            return 0;
        }

        if ($now < 1) {
            throw new \InvalidArgumentException('ActivityPub follower delivery requires a positive timestamp.');
        }

        if ($localActorIds === []) {
            $localActorIds = [$activity->actorId];
        }

        $actorIds = [];
        foreach ($localActorIds as $actorId) {
            if ($actorId < 1) {
                throw new \InvalidArgumentException('ActivityPub follower delivery has an invalid local actor.');
            }

            $actorIds[$actorId] = $actorId;
        }

        $placeholders = [];
        $parameters   = [];
        foreach (array_values($actorIds) as $index => $actorId) {
            $name                  = 'local_actor_' . $index;
            $placeholders[]        = ':' . $name;
            $parameters[$name]     = $actorId;
        }

        $query = $this->dbLayer->select('remote.actor_url, remote.inbox_url, remote.shared_inbox_url')
            ->from(ActivityPubSchema::FOLLOW_TABLE . ' AS follow')
            ->innerJoin(
                ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS remote',
                'remote.id = follow.remote_actor_id',
            )
            ->where('follow.local_actor_id IN (' . implode(', ', $placeholders) . ')')
            ->andWhere('follow.direction = :direction')->setParameter('direction', 'incoming')
            ->andWhere('follow.state = :follow_state')->setParameter('follow_state', 'accepted')
            ->andWhere('remote.state = :remote_state')->setParameter('remote_state', 'active')
            ->orderBy('remote.id')
        ;
        foreach ($parameters as $name => $actorId) {
            $query->setParameter($name, $actorId);
        }

        $rows = $query->execute()
            ->fetchAssocAll()
        ;

        /** @var array<string, array{url: string, origin: string, recipients: array<string, string>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $sharedInbox = $row['shared_inbox_url'] ?? null;
            $personalInbox = $row['inbox_url'] ?? null;
            $inbox = \is_string($sharedInbox) && $sharedInbox !== '' ? $sharedInbox : $personalInbox;
            $actorUrl = $row['actor_url'] ?? null;
            if (!\is_string($inbox) || !\is_string($actorUrl) || $actorUrl === '') {
                throw new \RuntimeException('An accepted ActivityPub follower has incomplete delivery endpoints.');
            }

            $origin = $this->httpsOrigin($inbox);
            $hash   = hash('sha256', $inbox);
            $groups[$hash] ??= ['url' => $inbox, 'origin' => $origin, 'recipients' => []];
            if (!hash_equals($groups[$hash]['url'], $inbox)) {
                throw new \RuntimeException('An ActivityPub inbox SHA-256 collision was detected.');
            }

            $groups[$hash]['recipients'][$actorUrl] = $actorUrl;
        }

        $inserted = 0;
        foreach ($groups as $group) {
            ksort($group['recipients'], SORT_STRING);
            $inserted += $this->insertPlannedDelivery(
                $activity,
                $group['url'],
                array_values($group['recipients']),
                $now,
            );
        }

        return $inserted;
    }

    /** @param non-empty-list<string> $recipients */
    public function planDirect(
        StoredActivityRepresentation $activity,
        string                       $inboxUrl,
        array                        $recipients,
        int                          $now,
    ): int {
        if (!\in_array($activity->deliveryIntent, [
            ActivityDeliveryIntent::DIRECT,
            ActivityDeliveryIntent::FOLLOWERS,
        ], true)) {
            throw new \InvalidArgumentException('A non-deliverable ActivityPub activity cannot target an explicit inbox.');
        }

        return $this->insertPlannedDelivery($activity, $inboxUrl, $recipients, $now);
    }

    public function recoverStaleClaims(int $now): int
    {
        return $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':delayed')->setParameter('delayed', DeliveryState::DELAYED->value)
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->set('available_at', ':available_at')->setParameter('available_at', $now)
            ->set('error_code', ':error_code')->setParameter('error_code', 'stale_claim')
            ->set('last_error', ':last_error')->setParameter('last_error', 'Recovered after the previous PHP process stopped during delivery.')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state = :in_flight')->setParameter('in_flight', DeliveryState::IN_FLIGHT->value)
            ->andWhere('claimed_at < :stale_before')->setParameter('stale_before', max(0, $now - self::STALE_CLAIM_SECONDS))
            ->execute()
            ->affectedRows()
        ;
    }

    public function cancelOutstanding(string $detail, int $now): int
    {
        if ($detail === '' || \strlen($detail) > 1_024 || $now < 1) {
            throw new \InvalidArgumentException('An ActivityPub delivery cancellation is invalid.');
        }

        $inFlight = (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::DELIVERY_TABLE)
            ->where('state = :state')->setParameter('state', DeliveryState::IN_FLIGHT->value)
            ->execute()
            ->result()
        ;
        if ($inFlight > 0) {
            throw new \DomainException('ActivityPub decommission must wait for the current delivery claim to finish.');
        }

        return $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':cancelled')->setParameter('cancelled', DeliveryState::CANCELLED->value)
            ->set('error_code', ':error_code')->setParameter('error_code', 'decommissioned')
            ->set('last_error', ':last_error')->setParameter('last_error', $detail)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state IN (:pending, :delayed)')
            ->setParameter('pending', DeliveryState::PENDING->value)
            ->setParameter('delayed', DeliveryState::DELAYED->value)
            ->execute()
            ->affectedRows()
        ;
    }

    public function outstandingCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::DELIVERY_TABLE)
            ->where('state IN (:pending, :delayed, :in_flight)')
            ->setParameter('pending', DeliveryState::PENDING->value)
            ->setParameter('delayed', DeliveryState::DELAYED->value)
            ->setParameter('in_flight', DeliveryState::IN_FLIGHT->value)
            ->execute()
            ->result()
        ;
    }

    public function claimNext(int $now): ?ClaimedDelivery
    {
        $this->expireOverdue($now);
        $row = $this->dbLayer->select('id, state')
            ->from(ActivityPubSchema::DELIVERY_TABLE)
            ->where('state IN (:pending, :delayed)')
            ->setParameter('pending', DeliveryState::PENDING->value)
            ->setParameter('delayed', DeliveryState::DELAYED->value)
            ->andWhere('available_at <= :available_at')->setParameter('available_at', $now)
            ->andWhere('expires_at > :expires_at')->setParameter('expires_at', $now)
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
        $updated = $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':in_flight')->setParameter('in_flight', DeliveryState::IN_FLIGHT->value)
            ->set('claim_token', ':claim_token')->setParameter('claim_token', $token)
            ->set('claimed_at', ':claimed_at')->setParameter('claimed_at', $now)
            ->set('last_attempt_at', ':last_attempt_at')->setParameter('last_attempt_at', $now)
            ->set('attempt_count', 'attempt_count + 1')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', (int)$row['id'])
            ->andWhere('state = :previous_state')->setParameter('previous_state', (string)$row['state'])
            ->andWhere('available_at <= :available_at')->setParameter('available_at', $now)
            ->andWhere('claim_token IS NULL')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            return null;
        }

        $claimed = $this->deliveryQuery()
            ->where('delivery.claim_token = :claim_token')->setParameter('claim_token', $token)
            ->andWhere('delivery.state = :state')->setParameter('state', DeliveryState::IN_FLIGHT->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($claimed) ? $this->hydrateClaimed($claimed) : null;
    }

    public function markDelivered(ClaimedDelivery $delivery, int $httpStatus, int $now): void
    {
        $this->completeClaim(
            $delivery,
            DeliveryState::DELIVERED,
            $now,
            $httpStatus,
            '',
            null,
            ['delivered_at' => $now],
        );
    }

    public function markDelayed(
        ClaimedDelivery $delivery,
        int             $availableAt,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $now,
        bool            $incrementAuthRefresh = false,
    ): void {
        if ($availableAt <= $now) {
            $availableAt = $now + 1;
        }

        $extra = ['available_at' => $availableAt];
        if ($incrementAuthRefresh) {
            $extra['auth_refresh_count'] = $delivery->authRefreshCount + 1;
        }

        $this->completeClaim(
            $delivery,
            DeliveryState::DELAYED,
            $now,
            $httpStatus,
            $errorCode,
            $detail,
            $extra,
        );
    }

    public function markRedirected(ClaimedDelivery $delivery, string $redirectUrl, int $now): void
    {
        $origin = $this->httpsOrigin($redirectUrl);
        if (\in_array($redirectUrl, [$delivery->requestUrl, ...$delivery->redirectChain], true)) {
            throw new \DomainException('The remote ActivityPub inbox redirect contains a loop.');
        }

        $chain   = [...$delivery->redirectChain, $delivery->requestUrl];
        $encoded = json_encode($chain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->completeClaim(
            $delivery,
            DeliveryState::DELAYED,
            $now,
            null,
            'redirect',
            'Following a validated remote inbox redirect.',
            [
                'available_at'        => $now + 1,
                'request_url'         => $redirectUrl,
                'effective_origin'    => $origin,
                'origin_hash'         => hash('sha256', $origin),
                'redirect_count'      => $delivery->redirectCount + 1,
                'redirect_chain_json' => $encoded,
            ],
        );
    }

    public function markFailed(
        ClaimedDelivery $delivery,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $now,
    ): void {
        $this->completeClaim(
            $delivery,
            DeliveryState::FAILED,
            $now,
            $httpStatus,
            $errorCode,
            $detail,
        );
    }

    public function recordAttempt(
        ClaimedDelivery $delivery,
        string          $result,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $startedAt,
        int             $completedAt,
    ): void {
        $this->dbLayer->insert(ActivityPubSchema::DELIVERY_ATTEMPT_TABLE)
            ->values([
                'delivery_id'   => ':delivery_id',
                'attempt_number' => ':attempt_number',
                'result'        => ':result',
                'http_status'   => ':http_status',
                'error_code'    => ':error_code',
                'detail'        => ':detail',
                'started_at'    => ':started_at',
                'completed_at'  => ':completed_at',
                'compact_after' => ':compact_after',
            ])
            ->onConflictDoNothing('delivery_id', 'attempt_number')
            ->execute([
                'delivery_id'    => $delivery->id,
                'attempt_number' => $delivery->attemptCount,
                'result'         => mb_substr($result, 0, 16),
                'http_status'    => $httpStatus,
                'error_code'     => mb_substr($errorCode, 0, 64),
                'detail'         => mb_substr($detail, 0, 4_000),
                'started_at'     => $startedAt,
                'completed_at'   => $completedAt,
                'compact_after'  => $completedAt + 30 * 24 * 60 * 60,
            ])
        ;
    }

    public function earliestPendingAt(): ?int
    {
        $value = $this->dbLayer->select('MIN(available_at)')
            ->from(ActivityPubSchema::DELIVERY_TABLE)
            ->where('state IN (:pending, :delayed)')
            ->setParameter('pending', DeliveryState::PENDING->value)
            ->setParameter('delayed', DeliveryState::DELAYED->value)
            ->execute()
            ->result()
        ;

        return $value === null || $value === false ? null : (int)$value;
    }

    private function deliveryQuery(): \Register\Core\Pdo\QueryBuilder\SelectBuilder
    {
        return $this->dbLayer->select('delivery.*, activity.actor_id, activity.serialized_body, activity.body_hash')
            ->from(ActivityPubSchema::DELIVERY_TABLE . ' AS delivery')
            ->innerJoin(ActivityPubSchema::ACTIVITY_TABLE . ' AS activity', 'activity.id = delivery.activity_id')
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrateClaimed(array $row): ClaimedDelivery
    {
        try {
            $recipients = $this->decodeStringList((string)$row['recipient_json']);
            $redirects  = $this->decodeStringList((string)$row['redirect_chain_json']);

            return new ClaimedDelivery(
                (int)$row['id'],
                (int)$row['activity_id'],
                (int)$row['actor_id'],
                (string)$row['serialized_body'],
                (string)$row['body_hash'],
                (string)$row['inbox_url'],
                (string)$row['request_url'],
                (string)$row['effective_origin'],
                $recipients,
                (int)$row['attempt_count'],
                (int)$row['auth_refresh_count'],
                (int)$row['redirect_count'],
                $redirects,
                (int)$row['expires_at'],
                (string)$row['claim_token'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub delivery is invalid.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \JsonException('Expected a JSON string list.');
        }

        $result = [];
        foreach ($decoded as $value) {
            if (!\is_string($value) || $value === '') {
                throw new \JsonException('Expected a non-empty string list item.');
            }

            $result[] = $value;
        }

        return $result;
    }

    /** @param array<string, int|string> $extra */
    private function completeClaim(
        ClaimedDelivery $delivery,
        DeliveryState   $state,
        int             $now,
        ?int            $httpStatus,
        string          $errorCode,
        ?string         $detail,
        array           $extra = [],
    ): void {
        $query = $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':state')->setParameter('state', $state->value)
            ->set('claim_token', 'NULL')
            ->set('claimed_at', 'NULL')
            ->set('http_status', ':http_status')->setParameter('http_status', $httpStatus)
            ->set('error_code', ':error_code')->setParameter('error_code', mb_substr($errorCode, 0, 64))
            ->set('last_error', ':last_error')->setParameter('last_error', $detail === null ? null : mb_substr($detail, 0, 4_000))
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $delivery->id)
            ->andWhere('state = :in_flight')->setParameter('in_flight', DeliveryState::IN_FLIGHT->value)
            ->andWhere('claim_token = :claim_token')->setParameter('claim_token', $delivery->claimToken)
        ;
        foreach ($extra as $column => $value) {
            $parameter = 'extra_' . $column;
            $query->set($column, ':' . $parameter)->setParameter($parameter, $value);
        }

        if ($query->execute()->affectedRows() !== 1) {
            throw new \RuntimeException('The ActivityPub delivery claim was lost before completion.');
        }
    }

    private function expireOverdue(int $now): void
    {
        $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':failed')->setParameter('failed', DeliveryState::FAILED->value)
            ->set('error_code', ':error_code')->setParameter('error_code', 'expired')
            ->set('last_error', ':last_error')->setParameter('last_error', 'The ActivityPub delivery retention deadline elapsed.')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('state IN (:pending, :delayed)')
            ->setParameter('pending', DeliveryState::PENDING->value)
            ->setParameter('delayed', DeliveryState::DELAYED->value)
            ->andWhere('expires_at <= :expires_at')->setParameter('expires_at', $now)
            ->execute()
        ;
    }

    /** @param list<string> $recipients */
    private function insertPlannedDelivery(
        StoredActivityRepresentation $activity,
        string                       $inboxUrl,
        array                        $recipients,
        int                          $now,
    ): int {
        $origin = $this->httpsOrigin($inboxUrl);
        $hash   = hash('sha256', $inboxUrl);
        $uniqueRecipients = [];
        foreach ($recipients as $recipient) {
            if ($recipient === '' || \strlen($recipient) > 2_048) {
                throw new \InvalidArgumentException('An ActivityPub delivery recipient is invalid.');
            }

            $uniqueRecipients[$recipient] = $recipient;
        }

        if ($uniqueRecipients === []) {
            throw new \InvalidArgumentException('A direct ActivityPub delivery requires at least one recipient.');
        }

        ksort($uniqueRecipients, SORT_STRING);
        $recipientJson = json_encode(
            array_values($uniqueRecipients),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $result = $this->dbLayer->insert(ActivityPubSchema::DELIVERY_TABLE)
            ->values([
                'activity_id'         => ':activity_id',
                'inbox_url_hash'      => ':inbox_url_hash',
                'inbox_url'           => ':inbox_url',
                'request_url'         => ':request_url',
                'redirect_count'      => '0',
                'redirect_chain_json' => ':redirect_chain_json',
                'effective_origin'    => ':effective_origin',
                'origin_hash'         => ':origin_hash',
                'recipient_json'      => ':recipient_json',
                'state'               => ':state',
                'attempt_count'       => '0',
                'auth_refresh_count'  => '0',
                'available_at'        => ':available_at',
                'expires_at'          => ':expires_at',
                'error_code'          => ':error_code',
                'created_at'          => ':created_at',
                'updated_at'          => ':updated_at',
            ])
            ->onConflictDoNothing('activity_id', 'inbox_url_hash')
            ->execute([
                'activity_id'         => $activity->id,
                'inbox_url_hash'      => $hash,
                'inbox_url'           => $inboxUrl,
                'request_url'         => $inboxUrl,
                'redirect_chain_json' => '[]',
                'effective_origin'    => $origin,
                'origin_hash'         => hash('sha256', $origin),
                'recipient_json'      => $recipientJson,
                'state'               => DeliveryState::PENDING->value,
                'available_at'        => $now,
                'expires_at'          => $now + self::DELIVERY_TTL_SECONDS,
                'error_code'          => '',
                'created_at'          => $now,
                'updated_at'          => $now,
            ])
        ;
        $affectedRows = $result->affectedRows();
        if ($affectedRows === 0) {
            $this->mergeRecipients($activity->id, $hash, $inboxUrl, $uniqueRecipients);
        }

        return $affectedRows;
    }

    /** @param array<string, string> $newRecipients */
    private function mergeRecipients(
        int    $activityId,
        string $inboxHash,
        string $inboxUrl,
        array  $newRecipients,
    ): void {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $row = $this->dbLayer->select('inbox_url', 'recipient_json')
                ->from(ActivityPubSchema::DELIVERY_TABLE)
                ->where('activity_id = :activity_id')->setParameter('activity_id', $activityId)
                ->andWhere('inbox_url_hash = :inbox_url_hash')->setParameter('inbox_url_hash', $inboxHash)
                ->execute()
                ->fetchAssoc()
            ;
            if (!\is_array($row)
                || !\is_string($row['inbox_url'] ?? null)
                || !hash_equals($row['inbox_url'], $inboxUrl)
                || !\is_string($row['recipient_json'] ?? null)
            ) {
                throw new \RuntimeException('An ActivityPub delivery inbox SHA-256 collision was detected.');
            }

            $storedJson = $row['recipient_json'];
            try {
                $storedRecipients = $this->decodeStringList($storedJson);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('A stored ActivityPub delivery recipient list is invalid.', 0, $exception);
            }

            $merged = $newRecipients;
            foreach ($storedRecipients as $recipient) {
                $merged[$recipient] = $recipient;
            }

            ksort($merged, SORT_STRING);
            $mergedJson = json_encode(
                array_values($merged),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if (hash_equals($storedJson, $mergedJson)) {
                return;
            }

            $updated = $this->dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
                ->set('recipient_json', ':new_recipient_json')->setParameter('new_recipient_json', $mergedJson)
                ->where('activity_id = :activity_id')->setParameter('activity_id', $activityId)
                ->andWhere('inbox_url_hash = :inbox_url_hash')->setParameter('inbox_url_hash', $inboxHash)
                ->andWhere('recipient_json = :old_recipient_json')->setParameter('old_recipient_json', $storedJson)
                ->execute()
                ->affectedRows()
            ;
            if ($updated === 1) {
                return;
            }
        }

        throw new \RuntimeException('Concurrent ActivityPub delivery recipient updates did not converge.');
    }

    private function httpsOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || \strlen($url) > 2_048
        ) {
            throw new \InvalidArgumentException('A remote ActivityPub inbox must be a bounded credential-free HTTPS URL.');
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        return 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port);
    }
}
