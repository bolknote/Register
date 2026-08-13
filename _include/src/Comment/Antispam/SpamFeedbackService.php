<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use Register\Comment\CommentRepository;
use Register\Comment\ContentCommentNotifier;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamFeedbackService
{
    public function __construct(
        private CommentRepository          $commentRepository,
        private SpamIdentityHasher         $hasher,
        private SpamFeatureExtractor       $featureExtractor,
        private SpamAssessmentRepository   $assessmentRepository,
        private SpamReputationRepository   $reputationRepository,
        private ContentCommentNotifier     $commentNotifier,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function markHam(
        int         $commentId,
        ContentType $contentType,
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_HAM, $contentType);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function markSpam(
        int         $commentId,
        ContentType $contentType,
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_SPAM, $contentType);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    private function mark(
        int         $commentId,
        string      $label,
        ContentType $contentType,
    ): bool
    {
        $comment = $this->commentRepository->findOfType($commentId, $contentType);
        if (!$comment instanceof \Register\Comment\Comment) {
            return false;
        }

        $domains      = $this->featureExtractor->domains($comment->text);
        $domainHashes = array_map($this->hasher->domain(...), $domains);
        $assessment   = new SpamAssessment(
            0,
            [],
            $this->hasher->text($comment->text),
            $this->hasher->email($comment->email),
            $this->hasher->ip($comment->ip),
            $domainHashes,
        );

        $previousLabel = $this->assessmentRepository->labelComment($commentId, $label, $assessment, $contentType);
        $this->reputationRepository->replaceLabel([
            'text'   => [$assessment->textHash],
            'email'  => [$assessment->emailHash],
            'ip'     => [$assessment->ipHash],
            'domain' => $assessment->domainHashes,
        ], $previousLabel, $label);

        if ($label === SpamReputationRepository::LABEL_HAM) {
            if (!$comment->shown && $comment->sent) {
                $this->commentRepository->setSent($commentId, $contentType, false);
            }

            if (!$comment->shown) {
                $this->commentNotifier->notify($commentId, $contentType);
            }

            $this->commentRepository->publish($commentId, $contentType);
        } else {
            $this->commentRepository->markSpam($commentId, $contentType);
        }

        return true;
    }
}
