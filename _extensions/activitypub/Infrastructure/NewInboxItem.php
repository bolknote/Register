<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Extension\activitypub\Domain\ProtocolLimits;

final readonly class NewInboxItem
{
    public string $deduplicationHash;

    public string $activityUrlHash;

    public string $bodyHash;

    public string $actorUrlHash;

    public function __construct(
        public ?int   $targetLocalActorId,
        public string $activityType,
        public string $activityUrl,
        public string $actorUrl,
        public string $keyId,
        public string $signatureType,
        public string $effectiveOrigin,
        public string $rawBody,
        public string $transportJson,
        public int    $receivedAt,
    ) {
        if (($targetLocalActorId !== null && $targetLocalActorId < 1)
            || preg_match('/^[A-Za-z][A-Za-z0-9]{0,31}$/D', $activityType) !== 1
            || !str_starts_with($activityUrl, 'https://')
            || !str_starts_with($actorUrl, 'https://')
            || !str_starts_with($keyId, 'https://')
            || !\in_array($signatureType, ['legacy', 'rfc9421'], true)
            || !str_starts_with($effectiveOrigin, 'https://')
            || $rawBody === ''
            || \strlen($rawBody) > ProtocolLimits::INBOX_BODY_BYTES
            || $transportJson === ''
            || \strlen($transportJson) > 65_536
            || $receivedAt < 1
        ) {
            throw new \InvalidArgumentException('A new ActivityPub inbox item is invalid.');
        }

        $this->deduplicationHash = hash('sha256', "activity\0" . $activityUrl);
        $this->activityUrlHash   = hash('sha256', $activityUrl);
        $this->bodyHash          = hash('sha256', $rawBody);
        $this->actorUrlHash      = hash('sha256', $actorUrl);
    }
}
