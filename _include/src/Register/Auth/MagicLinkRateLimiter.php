<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Psr\Log\LoggerInterface;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Pdo\DbLayer;

/** Limits email delivery without storing an address or an IP address in plaintext. */
final readonly class MagicLinkRateLimiter
{
    public const int WINDOW_SECONDS = 15 * 60;

    public const int EMAIL_LIMIT = 3;

    public const int IP_LIMIT = 10;

    private const string EMAIL_BUCKET = 'auth_mail_email';

    private const string IP_BUCKET = 'auth_mail_ip';

    public function __construct(
        private DbLayer            $dbLayer,
        private SpamIdentityHasher $hasher,
        private LoggerInterface    $logger,
    ) {
    }

    /** @throws MagicLinkRateLimitException */
    public function consume(string $clientIp, string $email, ?int $now = null): void
    {
        $now ??= time();
        $buckets = [
            [self::IP_BUCKET, $this->ipKey($clientIp), self::IP_LIMIT],
            [self::EMAIL_BUCKET, $this->emailKey($email), self::EMAIL_LIMIT],
        ];

        try {
            $retryAfter = 0;
            foreach ($buckets as [$type, $key, $limit]) {
                $stats = $this->stats($type, $key, $now - self::WINDOW_SECONDS);
                if ($stats['count'] < $limit) {
                    continue;
                }

                $retryAfter = max(
                    $retryAfter,
                    $stats['oldest'] + self::WINDOW_SECONDS - $now + 1,
                );
            }

            if ($retryAfter > 0) {
                throw new MagicLinkRateLimitException($retryAfter);
            }

            foreach ($buckets as [$type, $key]) {
                $this->dbLayer
                    ->insert('spam_rate_events')
                    ->setValue('bucket_type', ':bucket_type')->setParameter('bucket_type', $type)
                    ->setValue('bucket_key', ':bucket_key')->setParameter('bucket_key', $key)
                    ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
                    ->execute()
                ;
            }
        } catch (MagicLinkRateLimitException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            $this->logger->error('Email sign-in rate limiter failed.', ['exception' => $throwable]);
            throw new \RuntimeException('Unable to protect email sign-in delivery.', 0, $throwable);
        }
    }

    /** @return array{count: int, oldest: int} */
    private function stats(string $type, string $key, int $windowStart): array
    {
        $row = $this->dbLayer
            ->select('COUNT(*) AS event_count', 'MIN(created_at) AS oldest_event')
            ->from('spam_rate_events')
            ->where('bucket_type = :bucket_type')->setParameter('bucket_type', $type)
            ->andWhere('bucket_key = :bucket_key')->setParameter('bucket_key', $key)
            ->andWhere('created_at >= :window_start')->setParameter('window_start', $windowStart)
            ->execute()
            ->fetchAssoc()
        ;

        return [
            'count'  => $row === false ? 0 : (int)$row['event_count'],
            'oldest' => $row === false || $row['oldest_event'] === null
                ? $windowStart
                : (int)$row['oldest_event'],
        ];
    }

    private function ipKey(string $clientIp): string
    {
        $normalized = trim($clientIp);
        $packed = filter_var($normalized, FILTER_VALIDATE_IP) === false ? false : inet_pton($normalized);

        return $this->hasher->rateBucket('auth-mail-ip', $packed === false ? $normalized : bin2hex($packed));
    }

    private function emailKey(string $email): string
    {
        return $this->hasher->rateBucket('auth-mail-email', mb_strtolower(trim($email)));
    }
}
