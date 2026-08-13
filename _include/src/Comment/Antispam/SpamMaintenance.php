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

final readonly class SpamMaintenance
{
    private const int UNATTACHED_ASSESSMENT_RETENTION = 24 * 60 * 60;

    private const int UNLABELLED_ASSESSMENT_RETENTION = 180 * 24 * 60 * 60;

    public function __construct(
        private DbLayer                  $dbLayer,
        private SpamRateLimiter          $rateLimiter,
        private SpamAssessmentRepository $assessmentRepository,
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

        /** @var array<string, \Closure(): int> $operations */
        $operations = [
            'rate_events' => fn(): int => $this->rateLimiter->deleteExpired($now),
            'form_nonces' => fn(): int => $this->deleteExpiredNonces($now),
            'reputation' => fn(): int => $this->reputationRepository->deleteExpired($now),
            'unattached_assessments' => fn(): int => $this->assessmentRepository->deleteUnattachedOlderThan(
                $now - self::UNATTACHED_ASSESSMENT_RETENTION,
            ),
            'unlabelled_assessments' => fn(): int => $this->assessmentRepository->deleteUnlabelledOlderThan(
                $now - self::UNLABELLED_ASSESSMENT_RETENTION,
            ),
            'comment_assessment_orphans' => $this->assessmentRepository->deleteOrphans(...),
        ];

        $deleted = [];
        foreach ($operations as $storage => $operation) {
            try {
                $deleted[$storage] = $operation();
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

    private function deleteExpiredNonces(int $now): int
    {
        return $this->dbLayer
            ->delete('spam_form_nonces')
            ->where('expires_at < :now')->setParameter('now', $now)
            ->execute()
            ->affectedRows()
        ;
    }
}
