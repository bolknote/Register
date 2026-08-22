<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;

/** Small cross-request fixed-window limiter backed by the module database. */
final readonly class InboxRateLimiter
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** Returns the absolute retry timestamp, or null when the request was consumed. */
    public function consume(string $dimension, string $key, int $limit, int $windowSeconds, int $now): ?int
    {
        if (preg_match('/^[a-z_]{1,16}$/D', $dimension) !== 1
            || $key === ''
            || $limit < 1
            || $windowSeconds < 1
            || $now < 1
        ) {
            throw new \InvalidArgumentException('The ActivityPub inbox rate-limit request is invalid.');
        }

        $hash = hash('sha256', $dimension . "\0" . $key);
        $this->ensureBucket($hash, $dimension, $now);

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $row = $this->row($hash, $dimension);
            $windowStartedAt = (int)$row['window_started_at'];
            $requestCount    = (int)$row['request_count'];
            $blockedUntil    = (int)$row['blocked_until'];
            if ($blockedUntil > $now) {
                return $blockedUntil;
            }

            if ($windowStartedAt <= $now - $windowSeconds) {
                $newWindow = $now;
                $newCount  = 1;
                $newBlock  = 0;
            } else {
                $newWindow = $windowStartedAt;
                $newCount  = $requestCount + 1;
                $newBlock  = $newCount > $limit ? $windowStartedAt + $windowSeconds + 1 : 0;
            }

            $updated = $this->dbLayer->update(ActivityPubSchema::RATE_LIMIT_TABLE)
                ->set('window_started_at', ':new_window')->setParameter('new_window', $newWindow)
                ->set('request_count', ':new_count')->setParameter('new_count', $newCount)
                ->set('blocked_until', ':new_block')->setParameter('new_block', $newBlock)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->where('bucket_hash = :bucket_hash')->setParameter('bucket_hash', $hash)
                ->andWhere('window_started_at = :old_window')->setParameter('old_window', $windowStartedAt)
                ->andWhere('request_count = :old_count')->setParameter('old_count', $requestCount)
                ->andWhere('blocked_until = :old_block')->setParameter('old_block', $blockedUntil)
                ->execute()
                ->affectedRows()
            ;
            if ($updated === 1) {
                return $newBlock > $now ? $newBlock : null;
            }
        }

        return $now + 1;
    }

    private function ensureBucket(string $hash, string $dimension, int $now): void
    {
        $this->dbLayer->insert(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->values([
                'bucket_hash'       => ':bucket_hash',
                'dimension'         => ':dimension',
                'window_started_at' => ':window_started_at',
                'request_count'     => '0',
                'blocked_until'     => '0',
                'updated_at'        => ':updated_at',
            ])
            ->onConflictDoNothing('bucket_hash')
            ->execute([
                'bucket_hash'       => $hash,
                'dimension'         => $dimension,
                'window_started_at' => $now,
                'updated_at'        => $now,
            ])
        ;
    }

    /** @return array<string, mixed> */
    private function row(string $hash, string $dimension): array
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->where('bucket_hash = :bucket_hash')->setParameter('bucket_hash', $hash)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row) || (string)$row['dimension'] !== $dimension) {
            throw new \RuntimeException('The ActivityPub inbox rate-limit bucket is missing or invalid.');
        }

        return $row;
    }
}
