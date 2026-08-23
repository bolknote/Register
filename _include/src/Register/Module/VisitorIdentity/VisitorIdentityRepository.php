<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Core\Pdo\DbLayer;

final readonly class VisitorIdentityRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
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

    public function linkUser(string $visitorId, int $userId, int $now): void
    {
        $this->touchVisitor($visitorId, $now);

        $this->dbLayer->insert(Manifest::USER_LINK_TABLE)
            ->setValue('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
            ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $now)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->onConflictDoNothing('visitor_id', 'user_id')
            ->execute()
        ;

        $this->dbLayer->update(Manifest::USER_LINK_TABLE)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;
    }
}
