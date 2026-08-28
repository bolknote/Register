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

final readonly class SpamMaintenance
{
    public const array OPERATIONS = [
        'rate_events',
        'form_nonces',
        'reputation',
        'unattached_assessments',
        'unlabelled_assessments',
        'comment_assessment_orphans',
    ];

    private const int UNATTACHED_ASSESSMENT_RETENTION = 24 * 60 * 60;

    private const int UNLABELLED_ASSESSMENT_RETENTION = 180 * 24 * 60 * 60;

    public function __construct(
        private DbLayer                  $dbLayer,
        private SpamRateLimiter          $rateLimiter,
        private SpamAssessmentStoreInterface $assessmentRepository,
        private SpamReputationRepository $reputationRepository,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * @return array<string, int> Number of deleted rows by storage.
     */
    public function run(?int $now = null): array
    {
        $now ??= time();

        $deleted = [];
        foreach (self::OPERATIONS as $storage) {
            try {
                $deleted[$storage] = $this->runOperation($storage, $now);
            } catch (\Throwable $throwable) {
                $deleted[$storage] = 0;
                $this->logger->error('Antispam maintenance operation failed.', [
                    'storage'   => $storage,
                    'exception' => $throwable,
                ]);
            }
        }

        if (array_sum($deleted) > 0) {
            $this->logger->info('Antispam maintenance deleted expired data.', $deleted);
        }

        return $deleted;
    }

    public function runOperation(string $operation, ?int $now = null, ?int $limit = null): int
    {
        $now ??= time();

        return match ($operation) {
            'rate_events' => $this->rateLimiter->deleteExpired($now, $limit),
            'form_nonces' => $this->deleteExpiredNonces($now, $limit),
            'reputation' => $this->reputationRepository->deleteExpired($now, $limit),
            'unattached_assessments' => $this->assessmentRepository->deleteUnattachedOlderThan(
                $now - self::UNATTACHED_ASSESSMENT_RETENTION,
                $limit,
            ),
            'unlabelled_assessments' => $this->assessmentRepository->deleteUnlabelledOlderThan(
                $now - self::UNLABELLED_ASSESSMENT_RETENTION,
                $limit,
            ),
            'comment_assessment_orphans' => $this->assessmentRepository->deleteOrphans($limit),
            default => throw new \InvalidArgumentException('Unknown antispam maintenance operation: ' . $operation),
        };
    }

    private function deleteExpiredNonces(int $now, ?int $limit = null): int
    {
        $delete = $this->dbLayer
            ->delete('spam_form_nonces')
            ->where('expires_at < :now')->setParameter('now', $now)
        ;
        if ($limit === null) {
            return $delete->execute()->affectedRows();
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('Maintenance batch size must be positive.');
        }

        $hashes = $this->dbLayer
            ->select('nonce_hash')
            ->from('spam_form_nonces')
            ->where('expires_at < :now')->setParameter('now', $now)
            ->orderBy('expires_at', 'nonce_hash')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;
        if ($hashes === []) {
            return 0;
        }

        $placeholders = [];
        foreach ($hashes as $index => $hash) {
            if (!\is_string($hash)) {
                throw new \UnexpectedValueException('Invalid form nonce maintenance key.');
            }

            $parameter      = 'nonce_' . $index;
            $placeholders[] = ':' . $parameter;
            $delete->setParameter($parameter, $hash);
        }

        return $delete
            ->andWhere('nonce_hash IN (' . implode(', ', $placeholders) . ')')
            ->execute()
            ->affectedRows()
        ;
    }
}
