<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class ClaimedInboxItem
{
    /** @param list<string> $fetchRedirectChain */
    public function __construct(
        public int     $id,
        public ?int    $targetLocalActorId,
        public string  $activityType,
        public string  $activityUrl,
        public string  $actorUrl,
        public string  $keyId,
        public string  $signatureType,
        public string  $effectiveOrigin,
        public string  $rawBody,
        public string  $bodyHash,
        public string  $transportJson,
        public int     $attemptCount,
        public int     $keyRefreshCount,
        public bool    $forceKeyRefresh,
        public string  $fetchKind,
        public bool    $fetchSigned,
        public string  $fetchUrl,
        public int     $fetchRedirectCount,
        public array          $fetchRedirectChain,
        public string  $fetchedObjectJson,
        public string  $fetchedObjectHash,
        public int     $rawExpiresAt,
        public string  $claimToken,
    ) {
        if ($id < 1
            || ($targetLocalActorId !== null && $targetLocalActorId < 1)
            || !str_starts_with($activityUrl, 'https://')
            || !str_starts_with($actorUrl, 'https://')
            || !str_starts_with($keyId, 'https://')
            || !\in_array($signatureType, ['legacy', 'rfc9421'], true)
            || !str_starts_with($effectiveOrigin, 'https://')
            || preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1
            || !str_starts_with($fetchUrl, 'https://')
            || $attemptCount < 1
            || $keyRefreshCount < 0
            || !\in_array($fetchKind, ['actor', 'object', 'move_target', 'ready'], true)
            || $fetchRedirectCount < 0
            || ($fetchedObjectJson === '' && $fetchedObjectHash !== '')
            || ($fetchedObjectJson !== '' && !hash_equals(hash('sha256', $fetchedObjectJson), $fetchedObjectHash))
            || $rawExpiresAt < 1
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $claimToken) !== 1
        ) {
            throw new \InvalidArgumentException('A claimed ActivityPub inbox item is invalid.');
        }
    }
}
