<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Model\CommentNotifier;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamFeedbackService
{
    public function __construct(
        private DbLayer                    $dbLayer,
        private SpamIdentityHasher         $hasher,
        private SpamFeatureExtractor       $featureExtractor,
        private SpamAssessmentRepository   $assessmentRepository,
        private SpamReputationRepository   $reputationRepository,
        private CommentNotifier            $commentNotifier,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function markHam(
        int       $commentId,
        string    $targetType = 'article',
        string    $commentTable = 'art_comments',
        ?\Closure $notifier = null,
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_HAM, $targetType, $commentTable, $notifier);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function markSpam(
        int    $commentId,
        string $targetType = 'article',
        string $commentTable = 'art_comments',
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_SPAM, $targetType, $commentTable, null);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    private function mark(
        int       $commentId,
        string    $label,
        string    $targetType,
        string    $commentTable,
        ?\Closure $notifier,
    ): bool
    {
        if (preg_match('#^[a-z][a-z0-9_]*$#', $commentTable) !== 1) {
            throw new \InvalidArgumentException('Invalid comment table name.');
        }

        $comment = $this->dbLayer
            ->select('ip', 'email', 'text', 'shown', 'sent')
            ->from($commentTable)
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            return false;
        }

        $domains      = $this->featureExtractor->domains((string)$comment['text']);
        $domainHashes = array_map($this->hasher->domain(...), $domains);
        $assessment   = new SpamAssessment(
            0,
            [],
            $this->hasher->text((string)$comment['text']),
            $this->hasher->email((string)$comment['email']),
            $this->hasher->ip((string)$comment['ip']),
            $domainHashes,
        );

        $previousLabel = $this->assessmentRepository->labelComment($commentId, $label, $assessment, $targetType);
        $this->reputationRepository->replaceLabel([
            'text'   => [$assessment->textHash],
            'email'  => [$assessment->emailHash],
            'ip'     => [$assessment->ipHash],
            'domain' => $assessment->domainHashes,
        ], $previousLabel, $label);

        if ($label === SpamReputationRepository::LABEL_HAM) {
            if (!(bool)$comment['shown'] && (bool)$comment['sent']) {
                $this->dbLayer
                    ->update($commentTable)
                    ->set('sent', '0')
                    ->where('id = :id')->setParameter('id', $commentId)
                    ->execute()
                ;
            }

            if (!(bool)$comment['shown']) {
                if ($notifier instanceof \Closure) {
                    $notifier($commentId);
                } elseif ($targetType === 'article') {
                    $this->commentNotifier->notify($commentId);
                } else {
                    throw new \LogicException('A notifier is required for non-article comments.');
                }
            }

            $this->dbLayer
                ->update($commentTable)
                ->set('shown', '1')
                ->where('id = :id')->setParameter('id', $commentId)
                ->execute()
            ;
        } else {
            $this->dbLayer
                ->update($commentTable)
                ->set('shown', '0')
                ->set('sent', '1')
                ->where('id = :id')->setParameter('id', $commentId)
                ->execute()
            ;
        }

        return true;
    }
}
