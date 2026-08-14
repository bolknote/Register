<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Psr\Log\LoggerInterface;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Pdo\DbLayer;

/** Limits password guessing while storing neither login names nor IP addresses. */
final readonly class LoginRateLimiter
{
    public const int FAILURE_LIMIT = 5;

    public const int WINDOW_SECONDS = 15 * 60;

    private const string IP_BUCKET    = 'auth_ip';

    private const string LOGIN_BUCKET = 'auth_login';

    public function __construct(
        private DbLayer            $dbLayer,
        private SpamIdentityHasher $hasher,
        private LoggerInterface    $logger,
    ) {
    }

    /** Returns the number of seconds before another credential check may be attempted. */
    public function retryAfter(string $clientIp, string $login, ?int $now = null): int
    {
        $now ??= time();

        try {
            $result = $this->dbLayer
                ->select('bucket_type', 'COUNT(*) AS event_count', 'MIN(created_at) AS oldest_event')
                ->from('spam_rate_events')
                ->where('created_at >= :window_start')->setParameter('window_start', $now - self::WINDOW_SECONDS)
                ->andWhere('((bucket_type = :ip_type AND bucket_key = :ip_key) OR (bucket_type = :login_type AND bucket_key = :login_key))')
                ->setParameter('ip_type', self::IP_BUCKET)
                ->setParameter('ip_key', $this->ipKey($clientIp))
                ->setParameter('login_type', self::LOGIN_BUCKET)
                ->setParameter('login_key', $this->loginKey($login))
                ->groupBy('bucket_type')
                ->execute()
            ;

            $retryAfter = 0;
            while (($row = $result->fetchAssoc()) !== false) {
                if ((int)$row['event_count'] < self::FAILURE_LIMIT) {
                    continue;
                }

                $retryAfter = max(
                    $retryAfter,
                    (int)$row['oldest_event'] + self::WINDOW_SECONDS - $now + 1,
                );
            }

            return max(0, $retryAfter);
        } catch (\Throwable $throwable) {
            $this->logger->error('Login rate limiter check failed.', ['exception' => $throwable]);

            return 0;
        }
    }

    public function recordFailure(string $clientIp, string $login, ?int $now = null): void
    {
        $now ??= time();

        try {
            $this->record(self::IP_BUCKET, $this->ipKey($clientIp), $now);
            $this->record(self::LOGIN_BUCKET, $this->loginKey($login), $now);
        } catch (\Throwable $throwable) {
            $this->logger->error('Unable to record a failed login attempt.', ['exception' => $throwable]);
        }
    }

    public function clear(string $clientIp, string $login): void
    {
        try {
            $this->dbLayer
                ->delete('spam_rate_events')
                ->where('((bucket_type = :ip_type AND bucket_key = :ip_key) OR (bucket_type = :login_type AND bucket_key = :login_key))')
                ->setParameter('ip_type', self::IP_BUCKET)
                ->setParameter('ip_key', $this->ipKey($clientIp))
                ->setParameter('login_type', self::LOGIN_BUCKET)
                ->setParameter('login_key', $this->loginKey($login))
                ->execute()
            ;
        } catch (\Throwable $throwable) {
            $this->logger->error('Unable to clear failed login attempts.', ['exception' => $throwable]);
        }
    }

    private function record(string $bucketType, string $bucketKey, int $now): void
    {
        $this->dbLayer
            ->insert('spam_rate_events')
            ->setValue('bucket_type', ':bucket_type')->setParameter('bucket_type', $bucketType)
            ->setValue('bucket_key', ':bucket_key')->setParameter('bucket_key', $bucketKey)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->execute()
        ;
    }

    private function ipKey(string $clientIp): string
    {
        $normalized = trim($clientIp);
        $packed     = filter_var($normalized, FILTER_VALIDATE_IP) === false ? false : inet_pton($normalized);

        return $this->hasher->rateBucket('auth-ip', $packed === false ? $normalized : bin2hex($packed));
    }

    private function loginKey(string $login): string
    {
        return $this->hasher->rateBucket('auth-login', mb_strtolower(trim($login)));
    }
}
