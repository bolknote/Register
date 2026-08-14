<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use S2\Cms\Pdo\DbLayer;

final readonly class VisitorIdentityRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function findByFingerprintHash(string $fingerprintHash): ?string
    {
        $visitorId = $this->dbLayer->select('visitor_id')
            ->from(Manifest::FINGERPRINT_TABLE)
            ->where('fingerprint_hash = :fingerprint_hash')->setParameter('fingerprint_hash', $fingerprintHash)
            ->execute()
            ->result()
        ;

        return \is_string($visitorId) ? $visitorId : null;
    }

    public function totalVisitors(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(Manifest::VISITOR_TABLE)
            ->execute()
            ->result()
        ;
    }

    public function touchVisitor(string $visitorId, int $now): void
    {
        $this->dbLayer->insert(Manifest::VISITOR_TABLE)
            ->setValue('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->onConflictDoNothing('visitor_id')
            ->execute()
        ;

        $this->dbLayer->update(Manifest::VISITOR_TABLE)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->execute()
        ;
    }

    /**
     * Links only a previously unseen fingerprint. An existing link is never reassigned merely
     * because a client presents a different storage token.
     */
    public function linkFingerprintHash(string $fingerprintHash, string $visitorId, int $now): void
    {
        $this->dbLayer->insert(Manifest::FINGERPRINT_TABLE)
            ->setValue('fingerprint_hash', ':fingerprint_hash')->setParameter('fingerprint_hash', $fingerprintHash)
            ->setValue('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->onConflictDoNothing('fingerprint_hash')
            ->execute()
        ;

        $this->dbLayer->update(Manifest::FINGERPRINT_TABLE)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('fingerprint_hash = :fingerprint_hash')->setParameter('fingerprint_hash', $fingerprintHash)
            ->execute()
        ;
    }
}
