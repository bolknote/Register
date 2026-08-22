<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Module\LinkHealth\LinkHealthStatus;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\Manifest;
use Register\Core\Pdo\DbLayer;

final readonly class LinkHealthAdminRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @return array{total: int, usages: int, statuses: array<string, int>, inventory_ready: bool}
     */
    public function summary(): array
    {
        $statusRows = $this->dbLayer
            ->select('health_status, COUNT(*) AS target_count')
            ->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->groupBy('health_status')
            ->execute()
            ->fetchAssocAll()
        ;

        $statuses = [];
        $total    = 0;
        foreach ($statusRows as $row) {
            $count                            = (int)$row['target_count'];
            $statuses[(string)$row['health_status']] = $count;
            $total                           += $count;
        }

        $usages = $this->dbLayer
            ->select('COALESCE(SUM(cl.occurrence_count), 0)')
            ->from(Manifest::CONTENT_LINK_TABLE . ' AS cl')
            ->innerJoin(Manifest::TARGET_TABLE . ' AS target', 'target.id = cl.target_id')
            ->where('target.kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->execute()
            ->result()
        ;

        return [
            'total'           => $total,
            'usages'          => (int)$usages,
            'statuses'        => $statuses,
            'inventory_ready' => $this->inventoryIsReady(),
        ];
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function targets(?LinkHealthStatus $status, int $page, int $limit): array
    {
        if ($page < 1 || $limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Invalid link-health list pagination.');
        }

        $query = $this->dbLayer
            ->select('id, normalized_url, host, health_status, http_status, failure_count')
            ->addSelect('effective_url, last_error, first_seen_at, last_seen_at, last_checked_at')
            ->addSelect('last_success_at, next_check_at, archive_status, archive_url, archive_timestamp')
            ->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->orderBy(
                "CASE health_status WHEN 'broken' THEN 0 WHEN 'suspect' THEN 1 WHEN 'unknown' THEN 2 "
                . "WHEN 'restricted' THEN 3 WHEN 'blocked' THEN 4 WHEN 'ignored' THEN 5 ELSE 6 END",
                'last_seen_at DESC',
                'id',
            )
            ->limit($limit)
            ->offset(($page - 1) * $limit)
        ;
        if ($status instanceof LinkHealthStatus) {
            $query->andWhere('health_status = :health_status')->setParameter('health_status', $status->value);
        }

        $targets   = $query->execute()->fetchAssocAll();
        $targetIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $targets));
        $usageByTarget = $this->usageCounts($targetIds);

        $result = [];
        foreach ($targets as $target) {
            if (!\is_array($target)) {
                throw new \UnexpectedValueException('A link-health target row must be an array.');
            }

            $targetId = (int)$target['id'];
            $result[] = [
                ...$target,
                'id'               => $targetId,
                'http_status'      => $target['http_status'] === null ? null : (int)$target['http_status'],
                'failure_count'    => (int)$target['failure_count'],
                'first_seen_at'    => (int)$target['first_seen_at'],
                'last_seen_at'     => (int)$target['last_seen_at'],
                'last_checked_at'  => $target['last_checked_at'] === null ? null : (int)$target['last_checked_at'],
                'last_success_at'  => $target['last_success_at'] === null ? null : (int)$target['last_success_at'],
                'next_check_at'    => $target['next_check_at'] === null ? null : (int)$target['next_check_at'],
                'content_count'    => $usageByTarget[$targetId]['content_count'] ?? 0,
                'occurrence_count' => $usageByTarget[$targetId]['occurrence_count'] ?? 0,
            ];
        }

        return $result;
    }

    public function targetCount(?LinkHealthStatus $status): int
    {
        $query = $this->dbLayer->select('COUNT(*)')->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value);
        if ($status instanceof LinkHealthStatus) {
            $query->andWhere('health_status = :health_status')->setParameter('health_status', $status->value);
        }

        return (int)$query->execute()->result();
    }

    public function brokenCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->andWhere('health_status = :status')->setParameter('status', LinkHealthStatus::BROKEN->value)
            ->execute()->result();
    }

    public function ignore(int $targetId): void
    {
        $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', ':status')->setParameter('status', LinkHealthStatus::IGNORED->value)
            ->set('next_check_at', 'NULL')
            ->where('id = :id')->setParameter('id', $targetId)
            ->andWhere('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->execute();
    }

    public function unignore(int $targetId, int $now): void
    {
        $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', ':status')->setParameter('status', LinkHealthStatus::UNKNOWN->value)
            ->set('failure_count', '0')
            ->set('next_check_at', ':next_check_at')->setParameter('next_check_at', $now)
            ->where('id = :id')->setParameter('id', $targetId)
            ->andWhere('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->execute();
    }

    public function inventoryIsReady(): bool
    {
        $generation = $this->dbLayer->select('value')->from('config')
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute()->result();

        return (\is_int($generation) || (\is_string($generation) && ctype_digit($generation)))
            && (int)$generation >= Manifest::INVENTORY_GENERATION;
    }

    /**
     * @param list<int> $targetIds
     * @return array<int, array{content_count: int, occurrence_count: int}>
     */
    private function usageCounts(array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $placeholders = [];
        $query = $this->dbLayer
            ->select('target_id, COUNT(*) AS content_count, SUM(occurrence_count) AS occurrence_count')
            ->from(Manifest::CONTENT_LINK_TABLE)
            ->groupBy('target_id')
        ;
        foreach ($targetIds as $index => $targetId) {
            $name           = 'target_' . $index;
            $placeholders[] = ':' . $name;
            $query->setParameter($name, $targetId);
        }

        $query->where('target_id IN (' . implode(', ', $placeholders) . ')');

        $result = [];
        foreach ($query->execute()->fetchAssocAll() as $row) {
            $result[(int)$row['target_id']] = [
                'content_count'    => (int)$row['content_count'],
                'occurrence_count' => (int)$row['occurrence_count'],
            ];
        }

        return $result;
    }
}
