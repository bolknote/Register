<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use Psr\Log\LoggerInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamRateLimiter
{
    /** @var array<string, array{limit: int, window: int}> */
    private const array POLICIES = [
        'ip'      => ['limit' => 5, 'window' => 10 * 60],
        'email'   => ['limit' => 4, 'window' => 10 * 60],
        'visitor' => ['limit' => 5, 'window' => 10 * 60],
        'text'    => ['limit' => 3, 'window' => 24 * 60 * 60],
    ];

    public function __construct(
        private DbLayer              $dbLayer,
        private SpamIdentityHasher   $hasher,
        private LoggerInterface      $logger,
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
            $now        = time();
            $violations = [];
            foreach ($bucketKeys as $type => $bucketKey) {
                $policy = self::POLICIES[$type];
                $this->record($type, $bucketKey, $now);

                $count = $this->countSince($type, $bucketKey, $now - $policy['window']);
                if ($count > $policy['limit']) {
                    $violations[] = $type;
                }
            }

            return new SpamRateLimitResult($violations);
        } catch (\Throwable $throwable) {
            $this->logger->error('Comment rate limiter failed.', ['exception' => $throwable]);

            return new SpamRateLimitResult(available: false);
        }
    }

    /**
     * @throws DbLayerException
     */
    public function deleteOlderThan(int $timestamp): int
    {
        return $this->dbLayer
            ->delete('spam_rate_events')
            ->where('created_at < :timestamp')->setParameter('timestamp', $timestamp)
            ->execute()
            ->affectedRows()
        ;
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
    private function countSince(string $type, string $bucketKey, int $timestamp): int
    {
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from('spam_rate_events')
            ->where('bucket_type = :bucket_type')->setParameter('bucket_type', $type)
            ->andWhere('bucket_key = :bucket_key')->setParameter('bucket_key', $bucketKey)
            ->andWhere('created_at >= :timestamp')->setParameter('timestamp', $timestamp)
            ->execute()
            ->result()
        ;
    }
}
