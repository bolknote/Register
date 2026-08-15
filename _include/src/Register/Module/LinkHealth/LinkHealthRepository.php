<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;

final readonly class LinkHealthRepository
{
    private const int CHECK_HISTORY_RETENTION = 90 * 86400;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function findTarget(int $targetId): ?LinkTargetState
    {
        if ($targetId < 1) {
            return null;
        }

        $row = $this->dbLayer
            ->select('id, normalized_url, kind, health_status, failure_count, next_check_at')
            ->addSelect('last_seen_at, last_success_at, archive_status, archive_url')
            ->from(Manifest::TARGET_TABLE)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($row === false) {
            return null;
        }

        return new LinkTargetState(
            id: (int)$row['id'],
            url: (string)$row['normalized_url'],
            kind: LinkKind::from((string)$row['kind']),
            healthStatus: LinkHealthStatus::from((string)$row['health_status']),
            failureCount: (int)$row['failure_count'],
            nextCheckAt: $row['next_check_at'] === null ? null : (int)$row['next_check_at'],
            lastSeenAt: (int)$row['last_seen_at'],
            lastSuccessAt: $row['last_success_at'] === null ? null : (int)$row['last_success_at'],
            archiveStatus: ArchiveStatus::from((string)$row['archive_status']),
            archiveUrl: $row['archive_url'] === null ? null : (string)$row['archive_url'],
        );
    }

    public function hasUsages(int $targetId): bool
    {
        if ($targetId < 1) {
            return false;
        }

        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(Manifest::CONTENT_LINK_TABLE)
            ->where('target_id = :target_id')->setParameter('target_id', $targetId)
            ->execute()
            ->result() > 0;
    }

    public function probeWasRecorded(string $token): bool
    {
        if (!LinkQueue::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-check operation token is invalid.');
        }

        return $this->dbLayer->select('1')
            ->from(Manifest::CHECK_TABLE)
            ->where('probe_token = :probe_token')->setParameter('probe_token', $token)
            ->execute()
            ->fetchAssoc() !== false
        ;
    }

    public function recordProbe(
        string            $token,
        LinkTargetState   $target,
        LinkProbeResult   $probe,
        LinkHealthDecision $decision,
        int               $now,
    ): bool {
        if (!LinkQueue::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-check operation token is invalid.');
        }

        $error = $probe->error;
        if ($error === null && $probe->statusCode >= 400) {
            $error = 'HTTP ' . $probe->statusCode;
        }

        $updated = $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', ':health_status')->setParameter('health_status', $decision->status->value)
            ->set('http_status', ':http_status')->setParameter('http_status', $probe->statusCode > 0 ? $probe->statusCode : null)
            ->set('failure_count', ':failure_count')->setParameter('failure_count', $decision->failureCount)
            ->set('effective_url', ':effective_url')->setParameter('effective_url', $probe->effectiveUrl)
            ->set('last_error', ':last_error')->setParameter('last_error', $error)
            ->set('last_checked_at', ':last_checked_at')->setParameter('last_checked_at', $now)
            ->set('last_success_at', ':last_success_at')->setParameter('last_success_at', $decision->lastSuccessAt)
            ->set('next_check_at', ':next_check_at')->setParameter('next_check_at', $decision->nextCheckAt)
            ->where('id = :id')->setParameter('id', $target->id)
            ->andWhere('health_status = :previous_health_status')
            ->setParameter('previous_health_status', $target->healthStatus->value)
            ->andWhere('failure_count = :previous_failure_count')
            ->setParameter('previous_failure_count', $target->failureCount)
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            return false;
        }

        $this->dbLayer->insert(Manifest::CHECK_TABLE)->values([
            'target_id'     => ':target_id',
            'probe_token'   => ':probe_token',
            'checked_at'    => ':checked_at',
            'health_status' => ':health_status',
            'http_status'   => ':http_status',
            'effective_url' => ':effective_url',
            'error'         => ':error',
        ])->execute([
            'target_id'     => $target->id,
            'probe_token'   => $token,
            'checked_at'    => $now,
            'health_status' => $decision->status->value,
            'http_status'   => $probe->statusCode > 0 ? $probe->statusCode : null,
            'effective_url' => $probe->effectiveUrl,
            'error'         => $error,
        ]);

        return true;
    }

    public function pruneCheckHistory(int $now): void
    {
        $this->dbLayer->delete(Manifest::CHECK_TABLE)
            ->where('checked_at < :threshold')->setParameter('threshold', $now - self::CHECK_HISTORY_RETENTION)
            ->execute()
        ;
    }

    public function archiveLookupWasRecorded(int $targetId, string $token): bool
    {
        if ($targetId < 1 || !LinkQueue::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-archive operation identity is invalid.');
        }

        return $this->dbLayer->select('1')
            ->from(Manifest::TARGET_TABLE)
            ->where('id = :id')->setParameter('id', $targetId)
            ->andWhere('archive_lookup_token = :token')->setParameter('token', $token)
            ->execute()
            ->fetchAssoc() !== false
        ;
    }

    public function recordArchiveLookup(
        string              $token,
        LinkTargetState     $target,
        ArchiveLookupResult $result,
        int                 $now,
    ): bool {
        if (!LinkQueue::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-archive operation token is invalid.');
        }

        $updated = $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('archive_status', ':archive_status')->setParameter('archive_status', $result->status->value)
            ->set('archive_url', ':archive_url')->setParameter('archive_url', $result->url)
            ->set('archive_timestamp', ':archive_timestamp')->setParameter('archive_timestamp', $result->timestamp)
            ->set('archive_checked_at', ':archive_checked_at')->setParameter('archive_checked_at', $now)
            ->set('archive_lookup_token', ':archive_lookup_token')->setParameter('archive_lookup_token', $token)
            ->where('id = :id')->setParameter('id', $target->id)
            ->andWhere('health_status = :health_status')->setParameter('health_status', $target->healthStatus->value)
            ->andWhere('archive_status = :previous_archive_status')
            ->setParameter('previous_archive_status', $target->archiveStatus->value)
            ->execute()
            ->affectedRows()
        ;

        return $updated === 1;
    }

    public function recordArchiveError(int $targetId, int $now): void
    {
        $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('archive_status', ':archive_status')->setParameter('archive_status', ArchiveStatus::ERROR->value)
            ->set('archive_checked_at', ':archive_checked_at')->setParameter('archive_checked_at', $now)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()
        ;
    }

    /** @return list<LinkRepairUsage> */
    public function repairUsages(int $targetId): array
    {
        $rows = $this->dbLayer
            ->select('cl.source_content_id, cl.content_revision, c.content_type')
            ->from(Manifest::CONTENT_LINK_TABLE . ' AS cl')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS c', 'c.id = cl.source_content_id')
            ->where('cl.target_id = :target_id')->setParameter('target_id', $targetId)
            ->andWhere('c.published = 1')
            ->orderBy('cl.source_content_id')
            ->execute()
            ->fetchAssocAll()
        ;

        $usages = [];
        foreach ($rows as $row) {
            $usages[] = new LinkRepairUsage(
                new ContentId(ContentType::from((string)$row['content_type']), (int)$row['source_content_id']),
                (int)$row['content_revision'],
            );
        }

        return $usages;
    }
}
