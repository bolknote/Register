<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Delivery;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;

/** Durable per-origin cadence and small circuit breaker shared by all PHP requests. */
final readonly class OriginDeliveryThrottle
{
    private const int REQUEST_INTERVAL_SECONDS = 1;

    private const int CIRCUIT_FAILURES = 5;

    private const int CIRCUIT_OPEN_SECONDS = 15 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @return int|null Retry time, or null when the caller acquired the slot. */
    public function claim(string $origin, int $now): ?int
    {
        $hash = $this->bucketHash($origin);
        $this->ensureBucket($hash, $now);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $row = $this->row($hash);
            $blockedUntil = (int)$row['blocked_until'];
            if ($blockedUntil > $now) {
                return $blockedUntil;
            }

            $next = $now + self::REQUEST_INTERVAL_SECONDS;
            $updated = $this->dbLayer->update(ActivityPubSchema::RATE_LIMIT_TABLE)
                ->set('blocked_until', ':next')->setParameter('next', $next)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->where('bucket_hash = :bucket_hash')->setParameter('bucket_hash', $hash)
                ->andWhere('blocked_until = :previous')->setParameter('previous', $blockedUntil)
                ->execute()
                ->affectedRows()
            ;
            if ($updated === 1) {
                return null;
            }
        }

        return $now + self::REQUEST_INTERVAL_SECONDS;
    }

    public function recordSuccess(string $origin, int $now): void
    {
        $this->updateOutcome($origin, 0, $now, $now);
    }

    public function recordTransientFailure(string $origin, int $now): void
    {
        $hash = $this->bucketHash($origin);
        $this->ensureBucket($hash, $now);
        $row      = $this->row($hash);
        $failures = (int)$row['request_count'] + 1;
        $blocked  = $failures >= self::CIRCUIT_FAILURES
            ? $now + self::CIRCUIT_OPEN_SECONDS
            : max((int)$row['blocked_until'], $now + self::REQUEST_INTERVAL_SECONDS);
        $this->updateOutcome($origin, $failures, $blocked, $now);
    }

    public function blockUntil(string $origin, int $until, int $now): void
    {
        $hash = $this->bucketHash($origin);
        $this->ensureBucket($hash, $now);
        $row = $this->row($hash);
        $this->updateOutcome(
            $origin,
            (int)$row['request_count'],
            max($until, (int)$row['blocked_until']),
            $now,
        );
    }

    private function updateOutcome(string $origin, int $failures, int $blockedUntil, int $now): void
    {
        $this->dbLayer->update(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->set('request_count', ':request_count')->setParameter('request_count', $failures)
            ->set('window_started_at', ':window_started_at')->setParameter('window_started_at', $now)
            ->set('blocked_until', ':blocked_until')->setParameter('blocked_until', $blockedUntil)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('bucket_hash = :bucket_hash')->setParameter('bucket_hash', $this->bucketHash($origin))
            ->execute()
        ;
    }

    private function ensureBucket(string $hash, int $now): void
    {
        $this->dbLayer->insert(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->values([
                'bucket_hash'      => ':bucket_hash',
                'dimension'        => ':dimension',
                'window_started_at' => ':window_started_at',
                'request_count'    => '0',
                'blocked_until'    => '0',
                'updated_at'       => ':updated_at',
            ])
            ->onConflictDoNothing('bucket_hash')
            ->execute([
                'bucket_hash'       => $hash,
                'dimension'         => 'delivery',
                'window_started_at' => $now,
                'updated_at'        => $now,
            ])
        ;
    }

    /** @return array<string, mixed> */
    private function row(string $hash): array
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->where('bucket_hash = :bucket_hash')->setParameter('bucket_hash', $hash)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row) || (string)$row['dimension'] !== 'delivery') {
            throw new \RuntimeException('The ActivityPub delivery throttle bucket is missing or invalid.');
        }

        return $row;
    }

    private function bucketHash(string $origin): string
    {
        if (!str_starts_with($origin, 'https://') || \strlen($origin) > 255) {
            throw new \InvalidArgumentException('The ActivityPub delivery origin is invalid.');
        }

        return hash('sha256', 'delivery:' . strtolower($origin));
    }
}
