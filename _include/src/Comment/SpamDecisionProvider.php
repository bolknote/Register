<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment;

use S2\Cms\Comment\Antispam\SpamFeatureExtractor;

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
        $hasHtml   = $this->featureExtractor->hasHtml($comment->text);

        $rejectLinks     = false;
        $rejectSpam      = $report->shouldReject();
        $forceModeration = $report->isHam() && ($linkCount > 0 || $hasHtml);

        return new SpamDecision($report, $rejectLinks, $rejectSpam, $forceModeration);
    }

}
