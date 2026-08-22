<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Psr\Log\LoggerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

final readonly class SpamRateLimiter
{
    public function __construct(
        private DbLayer                  $dbLayer,
        private SpamIdentityHasher       $hasher,
        private SpamRatePolicyRepository $policyRepository,
        private LoggerInterface          $logger,
    ) {
    }

    public function consume(string $clientIp, string $email, string $text, string $visitorId): SpamRateLimitResult
    {
        $bucketKeys = [
            'ip'      => $this->hasher->ip($clientIp),
            'email'   => $this->hasher->email($email),
            'visitor' => $this->hasher->visitor($visitorId),
            'text'    => $this->hasher->text($text),
        ];

        try {
            $policies = $this->policyRepository->getPolicies();
            if ($policies === []) {
                return new SpamRateLimitResult();
            }

            $now       = time();
            $maxWindow = max(array_map(static fn(SpamRatePolicy $policy): int => $policy->windowSeconds, $policies));
            $this->deleteOlderThan($now - $maxWindow);

            $violations = [];
            $retryAfter = 0;
            foreach ($policies as $type => $policy) {
                $bucketKey = $bucketKeys[$type];
                $this->record($type, $bucketKey, $now);

                $windowStart = $now - $policy->windowSeconds;
                $stats = $this->statsSince($type, $bucketKey, $windowStart);
                if ($stats['count'] > $policy->limit) {
                    $violations[] = $type;
                    $retryAfter = max($retryAfter, $stats['oldest'] + $policy->windowSeconds - $now + 1);
                }
            }

            return new SpamRateLimitResult($violations, retryAfter: max(0, $retryAfter));
        } catch (\Throwable $throwable) {
            $this->logger->error('Comment rate limiter failed.', ['exception' => $throwable]);

            return new SpamRateLimitResult(available: false);
        }
    }

    /**
     * @throws DbLayerException
     */
    public function deleteOlderThan(int $timestamp, ?int $limit = null): int
    {
        if ($limit === null) {
            return $this->dbLayer
                ->delete('spam_rate_events')
                ->where('created_at < :timestamp')->setParameter('timestamp', $timestamp)
                ->execute()
                ->affectedRows()
            ;
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('Maintenance batch size must be positive.');
        }

        $ids = $this->dbLayer
            ->select('id')
            ->from('spam_rate_events')
            ->where('created_at < :timestamp')->setParameter('timestamp', $timestamp)
            ->orderBy('id')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;
        if ($ids === []) {
            return 0;
        }

        $delete       = $this->dbLayer
            ->delete('spam_rate_events')
            ->where('created_at < :timestamp')->setParameter('timestamp', $timestamp)
        ;
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $parameter      = 'id_' . $index;
            $placeholders[] = ':' . $parameter;
            $delete->setParameter($parameter, (int)$id, \PDO::PARAM_INT);
        }

        return $delete
            ->andWhere('id IN (' . implode(', ', $placeholders) . ')')
            ->execute()
            ->affectedRows()
        ;
    }

    /**
     * @throws DbLayerException
     */
    public function deleteExpired(int $now, ?int $limit = null): int
    {
        $policies = $this->policyRepository->getPolicies();
        $retention = $policies === []
            ? 25 * 60 * 60
            : max(array_map(static fn(SpamRatePolicy $policy): int => $policy->windowSeconds, $policies)) + 60 * 60;

        return $this->deleteOlderThan($now - $retention, $limit);
    }

    /**
     * @throws DbLayerException
     */
    private function record(string $type, string $bucketKey, int $now): void
    {
        $this->dbLayer
            ->insert('spam_rate_events')
            ->setValue('bucket_type', ':bucket_type')->setParameter('bucket_type', $type)
            ->setValue('bucket_key', ':bucket_key')->setParameter('bucket_key', $bucketKey)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    /** @return array{count: int, oldest: int} */
    private function statsSince(string $type, string $bucketKey, int $timestamp): array
    {
        $row = $this->dbLayer
            ->select('COUNT(*) AS event_count', 'MIN(created_at) AS oldest_event')
            ->from('spam_rate_events')
            ->where('bucket_type = :bucket_type')->setParameter('bucket_type', $type)
            ->andWhere('bucket_key = :bucket_key')->setParameter('bucket_key', $bucketKey)
            ->andWhere('created_at >= :timestamp')->setParameter('timestamp', $timestamp)
            ->execute()
            ->fetchAssoc()
        ;

        return [
            'count'  => $row === false ? 0 : (int)$row['event_count'],
            'oldest' => $row === false ? $timestamp : (int)$row['oldest_event'],
        ];
    }
}
