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
        $inserted = $this->dbLayer->insert(Manifest::VISITOR_TABLE)
            ->setValue('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->onConflictDoNothing('visitor_id')
            ->execute()
            ->affectedRows() > 0
        ;
        if ($inserted) {
            return;
        }

        $this->dbLayer->update(Manifest::VISITOR_TABLE)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->execute()
        ;
    }

    public function linkUser(string $visitorId, int $userId, int $now): void
    {
        $this->touchVisitor($visitorId, $now);

        $inserted = $this->dbLayer->insert(Manifest::USER_LINK_TABLE)
            ->setValue('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
            ->setValue('user_id', ':user_id')->setParameter('user_id', $userId)
            ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $now)
            ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->onConflictDoNothing('visitor_id', 'user_id')
            ->execute()
            ->affectedRows() > 0
        ;
        if ($inserted) {
            return;
        }

        $this->dbLayer->update(Manifest::USER_LINK_TABLE)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
        ;
    }

    /** Removes passive legacy identities only when no durable user action references them. */
    public function purgeUnreferencedBefore(int $before, int $limit = 100): int
    {
        if ($before <= 0 || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid visitor retention boundary.');
        }

        $prefix = $this->dbLayer->getPrefix();
        $references = [
            [Manifest::USER_LINK_TABLE, 'visitor_id'],
            [Manifest::FINGERPRINT_TABLE, 'visitor_id'],
            [\Register\Comment\CommentSchema::TABLE_NAME, 'visitor_id'],
            [\Register\Auth\PublicAuthSchema::MAGIC_LINKS_TABLE, 'visitor_id'],
            [\Register\Module\Reactions\Manifest::TABLE_NAME, 'visitor_id'],
        ];
        $existingReferences = [];
        foreach ($references as [$table, $field]) {
            if ($this->dbLayer->tableExists($table) && $this->dbLayer->fieldExists($table, $field)) {
                $existingReferences[] = [$prefix . $table, $field];
            }
        }

        $candidateClauses = [];
        foreach ($existingReferences as [$table, $field]) {
            $candidateClauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $table
                . ' WHERE ' . $field . ' = candidate.visitor_id)';
        }

        $candidates = $this->dbLayer->query(
            'SELECT candidate.visitor_id FROM ' . $prefix . Manifest::VISITOR_TABLE . ' AS candidate'
            . ' WHERE candidate.last_seen_at < :before'
            . ($candidateClauses === [] ? '' : ' AND ' . implode(' AND ', $candidateClauses))
            . ' ORDER BY candidate.last_seen_at ASC LIMIT ' . $limit,
            ['before' => $before],
        )->fetchColumn();

        $candidateIds = array_values(array_filter($candidates, is_string(...)));
        if ($candidateIds === []) {
            return 0;
        }

        $parameters = ['before' => $before];
        $placeholders = [];
        foreach ($candidateIds as $index => $visitorId) {
            $parameter = 'candidate_visitor_id_' . $index;
            $parameters[$parameter] = $visitorId;
            $placeholders[] = ':' . $parameter;
        }

        // Recheck every reference in the DELETE so a concurrent interaction that arrives after
        // candidate selection cannot be removed. One statement also avoids 100 delete round trips.
        $deleteClauses = [];
        foreach ($existingReferences as [$table, $field]) {
            $deleteClauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $table
                . ' WHERE ' . $field . ' = ' . $prefix . Manifest::VISITOR_TABLE . '.visitor_id)';
        }

        return $this->dbLayer->query(
            'DELETE FROM ' . $prefix . Manifest::VISITOR_TABLE
            . ' WHERE visitor_id IN (' . implode(', ', $placeholders) . ')'
            . ' AND last_seen_at < :before'
            . ($deleteClauses === [] ? '' : ' AND ' . implode(' AND ', $deleteClauses)),
            $parameters,
        )->affectedRows();
    }
}
