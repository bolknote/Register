<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use Psr\Log\LoggerInterface;
use S2\Cms\Comment\SpamDetectorComment;
use S2\Cms\Comment\SpamDetectorInterface;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Config\IntProxy;

final readonly class LocalSpamDetector implements SpamDetectorInterface
{
    public function __construct(
        private SpamRiskScorer           $scorer,
        private SpamAssessmentRepository $assessmentRepository,
        private SpamIdentityHasher       $hasher,
        private SpamFeatureExtractor     $featureExtractor,
        private LoggerInterface          $logger,
        private IntProxy                 $spamThreshold,
        private IntProxy                 $blatantThreshold,
    ) {
    }

    #[\Override]
    public function getReport(SpamDetectorComment $comment, string $clientIp): SpamDetectorReport
    {
        try {
            $assessment       = $this->scorer->assess($comment, $clientIp);
            $spamThreshold    = max(1, min(99, $this->spamThreshold->get()));
            $blatantThreshold = max($spamThreshold + 1, min(100, $this->blatantThreshold->get()));

            $status = match (true) {
                $assessment->hardBlock,
                $assessment->score >= $blatantThreshold => SpamDetectorReport::STATUS_BLATANT,
                $assessment->score >= $spamThreshold => SpamDetectorReport::STATUS_SPAM,
                default => SpamDetectorReport::STATUS_HAM,
            };

            $assessmentId = $this->assessmentRepository->save($assessment, $status);

            return match ($status) {
                SpamDetectorReport::STATUS_BLATANT => SpamDetectorReport::blatant(
                    $assessmentId,
                    $assessment->score,
                    $assessment->reasons,
                    $assessment->hardBlock,
                ),
                SpamDetectorReport::STATUS_SPAM => SpamDetectorReport::spam($assessmentId, $assessment->score, $assessment->reasons),
                default => SpamDetectorReport::ham($assessmentId, $assessment->score, $assessment->reasons),
            };
        } catch (\Throwable $throwable) {
            $this->logger->error('Local spam assessment failed.', ['exception' => $throwable]);

            $assessmentId = null;
            try {
                $assessment = new SpamAssessment(
                    0,
                    ['engine_failure' => 0],
                    $this->hasher->text($comment->text),
                    $this->hasher->email($comment->email),
                    $this->hasher->ip($clientIp),
                    array_map(
                        $this->hasher->domain(...),
                        $this->featureExtractor->domains($comment->text),
                    ),
                );
                $assessmentId = $this->assessmentRepository->save(
                    $assessment,
                    SpamDetectorReport::STATUS_FAILED,
                );
            } catch (\Throwable $auditThrowable) {
                $this->logger->error('Unable to audit the local spam assessment failure.', [
                    'exception' => $auditThrowable,
                ]);
            }

            return SpamDetectorReport::failed($assessmentId);
        }
    }
}
