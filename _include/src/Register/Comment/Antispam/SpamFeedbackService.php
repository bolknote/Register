<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment\Antispam;

use Register\Comment\CommentRepository;
use Register\Comment\ContentCommentNotifier;
use Register\Content\ContentType;
use Register\Core\Comment\Antispam\SpamAssessment;
use Register\Core\Comment\Antispam\SpamFeatureExtractor;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Comment\Antispam\SpamReputationRepository;
use Register\Core\Pdo\DbLayerException;

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
        ?ContentType $expectedContentType = null,
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_HAM, $expectedContentType);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function markSpam(
        int         $commentId,
        ?ContentType $expectedContentType = null,
    ): bool
    {
        return $this->mark($commentId, SpamReputationRepository::LABEL_SPAM, $expectedContentType);
    }

    /** @throws DbLayerException */
    public function isMarkedSpam(int $commentId, ContentType $contentType): bool
    {
        return $this->assessmentRepository->isSpam($commentId, $contentType);
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    private function mark(
        int         $commentId,
        string      $label,
        ?ContentType $expectedContentType,
    ): bool
    {
        $comment = $this->commentRepository->find($commentId);
        if (
            !$comment instanceof \Register\Comment\Comment
            || ($expectedContentType instanceof ContentType && $comment->contentId->type !== $expectedContentType)
        ) {
            return false;
        }

        $contentType = $comment->contentId->type;

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
        $reputationKeys = [
            'text'   => [$assessment->textHash],
            'ip'     => [$assessment->ipHash],
            'domain' => $assessment->domainHashes,
        ];
        // A legacy guest could write any address without proving that it belonged to them.
        // Keep such garbage out of identity reputation; only authenticated/verified identities
        // may teach the filter anything about an email address.
        if ($comment->userId !== null) {
            $reputationKeys['email'] = [$assessment->emailHash];
        }

        $this->reputationRepository->replaceLabel($reputationKeys, $previousLabel, $label);

        if ($label === SpamReputationRepository::LABEL_HAM) {
            if (!$comment->shown && $comment->sent) {
                $this->commentRepository->setSent($commentId, $contentType, false);
            }

            $this->commentRepository->publish($commentId, $contentType);

            if (!$comment->shown || !$comment->sent) {
                $this->commentNotifier->notify($commentId, $contentType);
            }
        } else {
            $this->commentRepository->markSpam($commentId, $contentType);
        }

        return true;
    }
}
