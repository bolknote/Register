<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

use Register\Core\Comment\Antispam\SpamFeatureExtractor;

readonly class SpamDecisionProvider implements SpamDecisionProviderInterface
{
    public function __construct(
        private SpamDetectorInterface $detector,
        private SpamFeatureExtractor  $featureExtractor,
    ) {
    }

    #[\Override]
    public function getVerdict(SpamDetectorComment $comment, string $clientIp): SpamDecision
    {
        $report    = $this->detector->getReport($comment, $clientIp);
        $linkCount = $this->featureExtractor->linkCount($comment->text);

        $rejectLinks     = false;
        $rejectSpam      = $report->shouldReject();
        $forceModeration = $report->isHam() && $linkCount > 0;

        return new SpamDecision($report, $rejectLinks, $rejectSpam, $forceModeration);
    }

}
